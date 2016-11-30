<?php

namespace Emergence\Git;

use Site;
use Gitonomy\Git\Admin AS GitAdmin;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Exception\ProcessException AS GitProcessException;
use Emergence\SSH\KeyPair;


class Source
{
    public static $defaultBranch = 'master';

    protected $id;
    protected $config;
    protected $repository;
    protected $trees;
    protected $deployKey;

    public static function getAll()
    {
        static $sources = null;

        if ($sources === null) {
            $sources = \Git::$repositories;

            // instantiate sources
            foreach ($sources AS $id => &$source) {
                if (is_array($source)) {
                    $source = new static($id, $source);
                }
            }
        }

        return $sources;
    }

    public static function getById($sourceId)
    {
        $sources = static::getAll();

        return isset($sources[$sourceId]) ? $sources[$sourceId] : null;
    }

    public static function getRepositoriesRootPath()
    {
        $path = Site::$rootPath . '/site-data/git';

        if (!is_dir($path)) {
            mkdir($path, 0770, true);
        }

        return $path;
    }


    public function __construct($id, $config = [])
    {
        $this->id = $id;
        $this->config = $config;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getConfig($key = null)
    {
        if ($key) {
            return isset($this->config[$key]) ? $this->config[$key] : null;
        }

        return $this->config;
    }

    public function getStatus()
    {
        if (!$this->isInitialized()) {
            return 'uninitialized';
        }

        // TODO: dirty?
        // TODO: out-of-sync?

        return 'clean';
    }

    public function isInitialized()
    {
        return (bool)$this->getRepository();
    }

    public function getRepositoryPath()
    {
        return static::getRepositoriesRootPath() . '/' . $this->id;
    }

    public function getPrivateKeyPath()
    {
        return $this->getRepositoryPath() . '.key';
    }

    public function getPublicKeyPath()
    {
        return $this->getRepositoryPath() . '.pub';
    }

    public function getRepository()
    {
        if (!isset($this->repository)) {
            $gitDir = $this->getRepositoryPath();
            $this->repository = is_dir($gitDir) ? new Repository($gitDir) : false;
        }

        return $this->repository;
    }

    public function getDeployKey()
    {
        if ($this->deployKey) {
            return $this->deployKey;
        }

        $privateKeyPath = $this->getPrivateKeyPath();
    	$publicKeyPath = $this->getPublicKeyPath();

        if (is_readable($privateKeyPath) && is_readable($publicKeyPath)) {
            $this->deployKey = KeyPair::load($privateKeyPath, $publicKeyPath);
        }

        return $this->deployKey;
    }

    public function setDeployKey(KeyPair $keyPair)
    {
		$privateKeyPath = $this->getPrivateKeyPath();
		file_put_contents($privateKeyPath, $keyPair->getPrivateKey() . PHP_EOL);
		chmod($privateKeyPath, 0600);

		$publicKeyPath = $this->getPublicKeyPath();
		file_put_contents($publicKeyPath, $keyPair->getPublicKey() . PHP_EOL);
		chmod($publicKeyPath, 0600);

        $this->deployKey = $keyPair;
    }

    public function getSshWrapperPath($create = true)
    {
        if ($this->getRemoteProtocol() != 'ssh' || !($privateKeyPath = $this->getPrivateKeyPath())) {
            return null;
        }

        $wrapperPath = $this->getRepositoryPath() . '.git.sh';

        if (!is_file($wrapperPath)) {
            file_put_contents(
                $wrapperPath,
                sprintf("#!/bin/bash\n\nssh -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no -i %s $1 $2\n", escapeshellarg($privateKeyPath))
            );
            chmod($wrapperPath, 0700);
        }

        return $wrapperPath;
    }

    /**
     * Return active or configured remote URL
     */
    public function getRemoteUrl()
    {
        try {
            if ($repository = $this->getRepository()) {
                return trim($repository->run('config', ['--get', 'remote.origin.url']));
            }
        } catch (GitProcessException $e) {
            // fall through to returning configured remote 
        }

        return $this->getConfig('remote');
    }

    public function getRemoteProtocol()
    {
        $remoteUrl = $this->getRemoteUrl();

        return strpos($remoteUrl, 'https://') === 0 || strpos($remoteUrl, 'http://') === 0 ? 'http' : 'ssh';
    }

    public function getTrees()
    {
        if (!$this->trees) {
            $this->trees = [];

        	foreach ($this->getConfig('trees') AS $treeKey => $treeValue) {
    			$this->trees[] = $this->getTreeOptions($treeKey, $treeValue);
        	}
        }

        return $this->trees;
    }

    public function getWorkingBranch()
    {
        if ($repository = $this->getRepository()) {
            $workingBranch = trim($repository->run('symbolic-ref', ['HEAD', '--short']));
        }

        return $workingBranch ?: $this->getConfig('workingBranch') ?: static::$defaultBranch;
    }

    public function getUpstreamBranch()
    {
        if ($repository = $this->getRepository()) {
            $upstreamBranch = trim($repository->run('rev-parse', ['--abbrev-ref', '@{upstream}']));

            if (strpos($upstreamBranch, 'origin/') === 0) {
                $upstreamBranch = substr($upstreamBranch, 7);
            }
        }

        return $upstreamBranch ?: $this->getConfig('originBranch') ?: static::$defaultBranch;
    }

    public function initialize()
    {
        if ($this->getRepository()) {
            throw new \Exception('repository already initialized');
        }

        // create new repo
        $this->repository = GitAdmin::init($this->getRepositoryPath(), false, [
            'environment_variables' => [
                'GIT_SSH' => $this->getSshWrapperPath()
            ]
        ]);

        // add remote
        $this->repository->run('remote', ['add', 'origin', $this->getRemoteUrl()]);

        // fetch upstream branch and checkout
        $upstreamBranch = $this->getUpstreamBranch();

        $this->repository->run('fetch', ['origin', $upstreamBranch]);
        $this->repository->run('checkout', ['-b', $this->getWorkingBranch(), "origin/$upstreamBranch"]);

        return true;
    }


    protected function getTreeOptions($key, $value)
	{
		if (is_string($value)) {
			$treeOptions = [
				'gitPath' => $value
			];
		} else {
			$treeOptions = $value;
		}

        $treeOptions['dataPath'] = false;

        if (!isset($treeOptions['localOnly'])) {
            $treeOptions['localOnly'] = $this->getConfig('localOnly') === null ? true : $this->getConfig('localOnly');
        }

		if (is_string($key)) {
			$treeOptions['vfsPath'] = $key;
		}

		if (!$treeOptions['vfsPath']) {
			$treeOptions['vfsPath'] = $treeOptions['path'] ?: $treeOptions['gitPath'];
		}

		if (!$treeOptions['gitPath']) {
			$treeOptions['gitPath'] = $treeOptions['path'] ?: $treeOptions['vfsPath'];
		}

		unset($treeOptions['path']);

	    if (is_string($treeOptions['exclude'])) {
	        $treeOptions['exclude'] = array($treeOptions['exclude']);
	    }

        if (!empty($_REQUEST['minId']) && ctype_digit($_REQUEST['minId'])) {
            $treeOptions['minId'] = $_REQUEST['minId'];
        }

        if (!empty($_REQUEST['maxId']) && ctype_digit($_REQUEST['maxId'])) {
            $treeOptions['maxId'] = $_REQUEST['maxId'];
        }

		return $treeOptions;
	}
}