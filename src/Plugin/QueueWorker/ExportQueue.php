<?php

namespace Drupal\digitalia_ltp_adapter\Plugin\QueueWorker;

use Drupal\Core\Annotation\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
use Drupal\digitalia_ltp_adapter\Utils;
use Drupal\digitalia_ltp_adapter\LtpSystemInterface;

/**
 * Export Queue Worker
 *
 * @QueueWorker(
 *   id = "digitalia_ltp_adapter_export_queue",
 *   title = @Translation("Export Queue"),
 *   cron = {"time" = 86400}
 *   )
 */
class ExportQueue extends QueueWorkerBase
{
	public function __construct(array $configuration, $plugin_id, $plugin_definition)
	{
		parent::__construct($configuration, $plugin_id, $plugin_definition);
	}

	/**
	* Processes an item in the queue.
	*
	* @param mixed $queue_item
	*   The queue item queue_item.
	*
	* @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
	* @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
	* @throws \Drupal\Core\Entity\EntityStorageException
	* @throws \Exception
	*/
	public function processItem($queue_item)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("Processing item from queue");

		$utils = new Utils();

		$ltp_system = \Drupal::service($utils->getConfig()->get("enabled_ltp_systems"));
		$ltp_system->setDirectory($queue_item["directory"]);
		$dirpath = $ltp_system->getBaseUrl() . "/" . $queue_item["directory"];

		if (!$utils->checkAndLock($dirpath, 2, 120)) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("Couldn't obtain lock for directory '" . $dirpath . "', aborting.");
			return;
		}

		try {
			$entity = \Drupal::entityTypeManager()->getStorage($queue_item['entity_type'])->loadByProperties(['uuid' => $queue_item['uuid']]);
			$entity = reset($entity);

			$writeback = $ltp_system->startIngest();

			$fields_written_to = false;
			foreach ($queue_item['fields'] as $id => $field_name) {
				if ($entity->get($field_name) != "") {
					$entity->set($field_name, $writeback[$id]);
					$fields_written_to = true;
				}

			}

			if ($fields_written_to) {
				// The directory, which is being locked must already exist
				$filesystem = \Drupal::service('file_system');
				$filesystem->prepareDirectory($dirpath, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

				$utils->checkAndLock($dirpath);
				$entity->save();
				$utils->removeLock($dirpath);
			}


		} catch (\Exception $e) {
			// unlocking only on failure, source directory is deleted otherwise
			\Drupal::logger('digitalia_ltp_adapter')->error($e->getMessage());
			$utils->removeLock($dirpath);
			return;
		}

		\Drupal::logger('digitalia_ltp_adapter')->debug("Item from queue processed");
	}
}
