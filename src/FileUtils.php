<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;


class FileUtils
{
	public function __construct()
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
	}

	/**
	 * Sadly NOT ATOMIC
	 * Tries to lock directory. Blocks for at most $timeout seconds
	 *
	 * @param String $dirpath
	 *   Full path to directory to lock
	 *
	 * @param int $interval
	 *   Lock checking interval in seconds
	 *
	 * @param int $timeout
	 *   Max blocking time in seconds
	 *
	 * @return bool
	 *   True if successfull
	 */
	public function checkAndLock(String $dirpath, int $interval = 2, int $timeout = 20)
	{
		//\Drupal::logger('digitalia_ltp_adapter')->debug("checkAndLock: start");
		$this->filesystem->prepareDirectory($dirpath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$total_wait = 0;

		while ($this->checkLock($dirpath)) {
			sleep($interval);
			$total_wait += $interval;
			if ($total_wait >= $timeout) {
				return false;
			}
		}

		$this->addLock($dirpath);
		//\Drupal::logger('digitalia_ltp_adapter')->debug("checkAndLock: end");
		return true;
	}

	/**
	 * Removes lock from a directory
	 *
	 * @param String $dirpath
	 *   Directory to unlock
	 */
	public function removeLock(String $dirpath)
	{
		$this->filesystem->delete($dirpath . "/lock");
	}

	private function checkLock(String $dirpath)
	{
		clearstatcache(true, $dirpath . "/lock");
		return file_exists($dirpath . "/lock");
	}

	// TODO: do it properly, not with EXISTS_REPLACE, we need to go deeper?
	private function addLock(String $dirpath)
	{
		$this->file_repository->writeData("", $dirpath . "/lock", FileSystemInterface::EXISTS_REPLACE);
	}
}
