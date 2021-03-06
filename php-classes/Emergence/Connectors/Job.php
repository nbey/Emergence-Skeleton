<?php

namespace Emergence\Connectors;

use ActiveRecord;
use HandleBehavior;
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;
use Emergence\Logger;

class Job extends ActiveRecord implements LoggerInterface
{

    use \Psr\Log\LoggerTrait;

    public $logEntries;

    // ActiveRecord configuration
    public static $tableName = 'connector_jobs';
    public static $singularNoun = 'connector job';
    public static $pluralNoun = 'connector jobs';

    // required for shared-table subclassing support
    public static $rootClass = __CLASS__;
    public static $defaultClass = __CLASS__;
    public static $subClasses = array(__CLASS__);

    public static $fields = array(
        'Title'
        ,'Handle' => array(
            'unique' => true
        )

        ,'Status' => array(
            'type' => 'enum'
            ,'values' => array('Template','Pending','InProgress','Completed','Failed','Abandoned')
            ,'default' => 'Pending'
        )

        ,'Connector'
        ,'TemplateID' => array(
            'type' => 'uint'
            ,'notnull' => false
        )

        ,'Direction' => array(
            'type' => 'enum'
            ,'values' => array('In','Out','Both')
            ,'notnull' => false
        )

        ,'Config' => array(
            'type' => 'json'
        )
        ,'Results' => array(
            'type' => 'json'
        )
    );

    public static $relationships = array(
        'Template' => array(
            'type' => 'one-one'
            ,'class' => __CLASS__
        )
        ,'TemplatedJobs' => array(
            'type' => 'one-many'
            ,'class' => __CLASS__
            ,'foreign' => 'TemplateID'
            ,'order' => array('ID' => 'DESC')
        )
    );


    public function save($deep = true)
    {
        // set handle
        if (!$this->Handle) {
            $this->Handle = HandleBehavior::generateRandomHandle($this);
        }

        // call parent
        return parent::save();
    }

    public function getConnectorTitle()
    {
        $className = $this->Connector;
        return $className::getTitle();
    }

    public function logRecordDelta(ActiveRecord $Record, $options = array())
    {
        $ignoreFields = is_array($options['ignoreFields']) ? $options['ignoreFields'] : array();
        $labelRenderers = is_array($options['labelRenderers']) ? $options['labelRenderers'] : array();
        $valueRenderers = is_array($options['valueRenderers']) ? $options['valueRenderers'] : array();
        $messageRenderer = is_callable($options['messageRenderer']) ? $options['messageRenderer'] : function ($logEntry) {
            $title = $logEntry['record']->getTitle();
            $class = $logEntry['record']->Class;

            if (strpos($title, $class) === false) {
                $title = "$class \"$title\"";
            }

            return $logEntry['action'].' '.$title;
        };

        $logEntry = array(
            'changes' => array()
            ,'level' => array_key_exists('level', $options) ? $options['level'] : LogLevel::NOTICE
            ,'record' => &$Record
        );

        foreach ($Record->originalValues AS $field => $from) {
            if (in_array($field, $ignoreFields)) {
                continue;
            }

            if (is_callable($labelRenderers[$field])) {
                $fieldLabel = call_user_func($labelRenderers[$field], $logEntry, $field);
            } elseif (is_string($labelRenderers[$field])) {
                $fieldLabel = $labelRenderers[$field];
            } else {
                $fieldLabel = $field;
            }

            $to = $Record->getValue($field);

            if (is_callable($valueRenderers[$field])) {
                $from = call_user_func($valueRenderers[$field], $from, $logEntry, $field, 'from');
                $to = call_user_func($valueRenderers[$field], $to, $logEntry, $field, 'to');
            }

            $logEntry['changes'][$fieldLabel] = array(
                'from' => $from
                ,'to' => $to
            );
        }

        if ($Record->isPhantom || $Record->isNew) {
            $logEntry['action'] = 'create';
        } elseif ($Record->isDirty && count($logEntry['changes'])) {
            $logEntry['action'] = 'update';
        } else {
            return;
        }

        $logEntry['message'] = call_user_func($messageRenderer, $logEntry);

        return $this->log(
            $logEntry['level'],
            $logEntry['message'],
            [
                'changes' => $changes,
                'record' => $Record
            ]
        );
    }

    public function logInvalidRecord(\ActiveRecord $Record)
    {
        return $this->log(
            LogLevel::WARNING,
            'Invalid {recordClass} record: {recordTitle}',
            [
                'validationErrors' => $Record->validationErrors,
                'recordClass' => get_class($Record),
                'recordTitle' => $Record->getTitle()
            ]
        );
    }

    public function logException(\Exception $e)
    {
        return $this->log(
            LogLevel::ERROR,
            'Exception({exceptionClass}): {exceptionMessage}',
            [
                'exception' => $e,
                'exceptionClass' => get_class($e),
                'exceptionMessage' => $e->getMessage()
            ]
        );
    }

    public function getLogPath()
    {
        return $this->isPhantom ? null : \Site::$rootPath.'/site-data/connector-jobs/'.$this->ID.'.json';
    }

    public function writeLog($logEntry, $compress = false)
    {
        $logPath = $this->getLogPath();

        if (!$logPath) { // record is phantom
            return;
        }

        $logDirectory = dirname($logPath);
        if (!is_dir($logDirectory)) {
            mkdir($logDirectory, 0777, true);
        }

        file_put_contents($logPath, json_encode($logEntry), FILE_APPEND | LOCK_EX);
        if ($compress === true) {
            exec("bzip2 $logPath");
        }
    }

    public function log($level, $message, array $context = [])
    {
        $entry = [
            'message' => $message,
            'context' => $context,
            'level' => $level
        ];

        $this->logEntries[] = $entry;
        $this->writeLog($entry);
    }
}