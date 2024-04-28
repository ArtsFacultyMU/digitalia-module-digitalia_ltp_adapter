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


class DigitaliaLtpUtils
{
	private $directories;
	private $filesystem;
	private $file_repository;
	private $entity_manager;
	private $languages;
	private $config;
	private $content_types_fields;
	private $debug_settings;

	// export types
	const EXPORT_FLAT = 0;
	const EXPORT_TREE = 1;
	const EXPORT_SINGLE = 2;
	const EXPORT_SEPARATE = 3;

	const UPDATE_CREATE = 10;
	const UPDATE_DELETE = 11;

	const METADATA_PATH = "metadata/metadata.json";

	public function __construct(array $debug_settings = null)
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->entity_manager = \Drupal::entityTypeManager();
		$this->languages = \Drupal::languageManager()->getLanguages();
		$this->directories = array();
		$this->config = \Drupal::config('digitalia_ltp_adapter.admin_settings');
		$this->content_types_fields = $this->parseFieldConfiguration();

		$this->debug_settings = $debug_settings;
		if ($debug_settings == null) {
			$this->debug_settings = array(
				'media_toggle' => true,
				'language_toggle' => false,
			);
		}
	}

	/**
	 * Parses content type/field configuration
	 *
	 * @return array
	 *   Array with parsed configuration
	 */
	private function parseFieldConfiguration()
	{
		$field_config = $this->config->get('field_configuration');
		$lines = explode("\n", $field_config);

		$parsed= array();
		foreach($lines as $line) {
			$split = explode("::", $line);
			$parsed[$split[0]][$split[1]] = $split[2];
		}

		return $parsed;
	}


	/**
	 * Gets list of all content types which are to be archived
	 *
	 * @return array
	 *   Array of content types
	 */
	public function getEnabledContentTypes()
	{
		$content_types = array();
		foreach ($this->content_types_fields as $type => $_value) {
			$content_types[$type] = 1;
		}

		return $content_types;
	}

	public function getConfig()
	{
		return $this->config;
	}

	/**
	 * Gets full entity uid for export directory structure (base object directory)
	 *
	 * @param $entity
	 *   Entity for which to generate full uid
	 *
	 * @return
	 *   String full uid
	 */
	public function getFullEntityUID($entity)
	{
		return $this->config->get('site_name') . "_" . $this->getEntityUID($entity);

	}

	/**
	 * Creates filename/directory friendly uid for entities
	 *
	 * @param $entity
	 *   Entity for which to generate uid
	 *
	 * @return
	 *   String uid
	 */
	private function getEntityUID($entity)
	{
		switch ($entity->getEntityTypeId()) {
		case "node":
			$prefix = "nid";
			break;
		case "media":
			$prefix = "mid";
			break;
		case "taxonomy_term":
			$prefix = "tid";
			break;
		case "taxonomy_vocabulary":
			$prefix = "vid";
			break;
		default:
			$prefix = "default";
			break;
		}

		return $prefix . "_" . $entity->id();

	}

	/**
	 * Adds a directory to ingest queue
	 *
	 * @param String $directory
	 *   Name of directory to be ingested
	 */
	public function addToQueue(String $directory)
	{
		$queue = \Drupal::service('queue')->get('digitalia_ltp_adapter_export_queue');
		if (!$queue->createItem($directory)) {
			\Drupal::logger('digitalia_ltp_adapter')->error("Object '" . $directory . "'couldn't be added to queue.");
		}
	}


	/**
	 * Removes directory from ingest queue
	 *
	 * @param String $directory
	 *   Name of directory to remove from ingest queue
	 *
	 * @param bool $all
	 *   Set to true to delete all intstances of $directory from queue
	 */
	public function removeFromQueue(String $directory, bool $all = false)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("removeFromQueue: start");
		$queue = \Drupal::service('queue')->get('digitalia_ltp_adapter_export_queue');
		$items = array();
		while ($item = $queue->claimItem()) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("removeFromQueue: direcotry = " . $directory . "; item = " . $item->data);
			if ($item->data == $directory) {
				\Drupal::logger('digitalia_ltp_adapter')->debug("removeFromQueue: item found");
				$queue->deleteItem($item);

				if (!$all) {
					return;
				}
			} else {
				array_push($items, $item);
			}
		}

		foreach ($items as $item) {
			$queue->releaseItem($item);
		}
	}

	/**
	 * Sadly NOT ATOMIC
	 * Tries to lock directory. Blocks for at most $timeout seconds
	 *
	 * @param String $directory
	 *   Directory to lock
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
	public function checkAndLock(String $directory, int $interval = 2, int $timeout = 20)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("checkAndLock: start");
		$path = $this->config->get('base_url') . "/" . $directory;
		$this->filesystem->prepareDirectory($path, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$total_wait = 0;

		while ($this->checkLock($directory)) {
			sleep($interval);
			$total_wait += $interval;
			if ($total_wait >= $timeout) {
				return false;
			}
		}

		$this->addLock($directory);
		\Drupal::logger('digitalia_ltp_adapter')->debug("checkAndLock: end");
		return true;
	}

	/**
	 * Removes lock from a directory
	 */
	public function removeLock(String $directory)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("removeLock: start");
		$this->filesystem->delete($this->config->get('base_url') . "/" . $directory . "/lock");
	}

	private function checkLock(String $directory)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("checkLock: start, directory: '" . $directory . "'");
		clearstatcache(true, $this->config->get('base_url') . "/" . $directory . "/lock");
		return file_exists($this->config->get('base_url') . "/" . $directory . "/lock");
	}

	private function addLock(String $directory)
	{
		\Drupal::logger('digitalia_ltp_adapter')->debug("addLock: start");
		$this->file_repository->writeData("", $this->config->get('base_url') . "/" . $directory . "/lock", FileSystemInterface::EXISTS_REPLACE);
		\Drupal::logger('digitalia_ltp_adapter')->debug("addLock: end");
	}


	/**
	 * Checks wether the entity is published
	 *
	 * @param $entity
	 *  Drupal entity
	 *
	 * @return bool
	 */
	public function getEntityStatus($entity)
	{
		// TODO: decide if language variants of an entity are separate entities
		$status = $entity->get("status");
		$published = false;

		// Untranslated entities store bool, translated entities are more complex
		if (is_bool($status)) {
			$published = $status;
		} else {
			$published = $status->getValue()[0]['value'];
		}

		return $published;
	}

	/**
	 * Tries to create/update entity and transfer it into Archivematica
	 *
	 * @param $entity
	 *   Drupal entity
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	public function updateEntity($entity, $update_mode)
	{
		// TODO: consolidate with archiveData?
		if (!$this->getConfig()->get('auto_generate_switch')) {
			return;
		}

		if ($this->getEnabledContentTypes()[$entity->bundle()]) {
			if ($this->getEntityStatus($entity)) {
				$this->archiveData($entity, $this::EXPORT_SINGLE, $update_mode);
			}
		}
	}

	/**
	 * Entrypoint for archiving
	 *
	 * @param $entity
	 *   Entity which is to be ingested into archivematica
	 *
	 * @param $export_mode
	 *   Sets the object export mode
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 *
	 * @return array
	 *   Array of object directories prepared for ingest
	 */
	public function archiveData($entity, $export_mode, $update_mode)
	{
		if (!$entity) {
			return $this->directories;
		}

		$type = $entity->getEntityTypeId();
		$this->archiveSourceEntity($entity, $export_mode, $this->config->get('site_name') . "_" . $this->getEntityUID($entity), $update_mode);

		return $this->directories;
	}

	/**
	 * Prepares necessary directories, starts metadata harvest and writes metadata
	 *
	 * @param $entity
	 *   Entity which is to be ingested into archivematica
	 *
	 * @param $export_mode
	 *   Sets the object export mode
	 *
	 * @param String $directory
	 *   Name of base object directory
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function archiveSourceEntity($entity, $export_mode, String $directory, $update_mode)
	{
		dpm("Preparing data...");

		if ($this->config->get('base_url') == "") {
			dpm("Base URL not set! Aborting.");
			return;
		}

		if (!$this->checkAndLock($directory)) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("Couldn't obtain lock for directory '$directory', pre cleanup");
			return;
		}

		$this->removeFromQueue($directory);
		$this->preExportCleanup($entity);

		if (!$this->checkAndLock($directory)) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("Couldn't obtain lock for directory '$directory', post cleanup");
			return;
		}

		$to_encode = array();
		$current_path = "objects";
		array_push($this->directories, $directory);

		$dir_url = $this->config->get('base_url') . "/" . $directory;
		$dir_metadata = $dir_url . "/metadata";
		$dir_objects = $dir_url . "/objects";

		$this->filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$this->filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

		$this->harvestMetadata($entity, $current_path, $to_encode, $export_mode, $dir_url, $update_mode);

		$encoded = json_encode($to_encode, JSON_UNESCAPED_SLASHES);

		dpm(json_encode($to_encode, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

		$this->file_repository->writeData($encoded, $dir_url . "/" . $this::METADATA_PATH, FileSystemInterface::EXISTS_REPLACE);

		$this->removeLock($directory);
		$this->addToQueue($directory);


		dpm("Data prepared!");
	}

	/**
	 * Appends metadata of all descendants of a entity
	 *
	 * @param object $entity
	 *   A drupal entity
	 *
	 * @param String $base_path
	 *   Base path of objects from $dir_url
	 *
	 * @param Array $to_encode
	 *   For appending metadata
	 *
	 * @param $export_mode
	 *   Determines export mode
	 *
	 * @param String $dir_url
	 *   URL of object directory, which is ingested to Archivematica
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function harvestMetadata($entity, String $base_path, Array &$to_encode, $export_mode, String $dir_url, $update_mode)
	{
		dpm("Entity type id: " . $entity->getEntityTypeId());
		$type = $entity->getEntityTypeId();

		$current_path = $base_path. "/" . $this->getEntityUID($entity);
		$dir_path = $dir_url . "/". $current_path;
		$filesystem = $this->filesystem->prepareDirectory($dir_path, FileSystemInterface::CREATE_DIRECTORY |
											       FileSystemInterface::MODIFY_PERMISSIONS);

		if ($type == "node") {
			$children = $this->entity_manager->getStorage('node')->loadByProperties(['field_member_of' => $entity->id()]);
			$media = $this->entity_manager->getStorage('media')->loadByProperties(['field_media_of' => $entity->id()]);
			$this->entityExtractMetadata($entity, $current_path, $to_encode, $dir_url, "", $update_mode);
		}

		if ($type == "media") {
			$this->harvestMedia($entity, $current_path, $to_encode, $dir_url, $update_mode);
		}

		if ($type == "taxonomy_term") {
			dpm("taxonomy_term type harvesting");
			$this->entityExtractMetadata($entity, $current_path, $to_encode, $dir_url, "", $update_mode);
		}

		if ($type == "taxonomy_vocabulary") {
			dpm("taxonomy_vocabulary type harvesting");
			$this->entityExtractMetadata($entity, $current_path, $to_encode, $dir_url, "", $update_mode);
		}


		if ($export_mode == $this::EXPORT_SEPARATE) {
			foreach($children as $child) {
				$this->archiveData($child, $export_mode);
			}

			foreach($media as $medium) {
				$this->archiveData($medium, $export_mode);
			}
		}
	}

	/**
	 * Extracts metadata from single entity
	 *
	 * @param $entity
	 *   Entity from which metadata is extracted
	 *
	 * @param String $current_path
	 *   Path of entity
	 *
	 * @param Array $to_encode
	 *   Array with metadata
	 *
	 * @param String $dir_url
	 *   URL of object directory, which is ingested to Archivematica
	 *
	 * @param String $filename
	 *   Entity filename, empty when not a file
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function entityExtractMetadata($entity, String $current_path, Array &$to_encode, String $dir_url, String $filename, $update_mode)
	{
		foreach ($this->languages as $lang => $_value) {
			$entity_translated = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $lang);

			if (!$filename) {
				$filepath = $current_path . "/" . $lang . ".txt";
				$this->file_repository->writeData("", $dir_url . '/' . $filepath, FileSystemInterface::EXISTS_REPLACE);
			} else {
				$filepath = $current_path . "/" . $filename;
			}

			$deleted = $update_mode == $this::UPDATE_DELETE;

			$metadata = array('filename' => $filepath, 'export_language' => $lang, 'status' => $this->getEntityStatus($entity_translated), 'deleted' => $deleted);

			$entity_bundle = $entity_translated->bundle();

			// TODO: deal with repeated fields
			// TODO: deal with multiple tokens for single field
			$token_service = \Drupal::token();
			$data = array(
				$entity_translated->getEntityTypeId() => $entity_translated,
			);

			$settings = array(
				'langcode' => $lang,
				'clear' => true,
			);

			foreach ($this->content_types_fields[$entity_bundle] as $name => $value) {
				dpm($token_service->replacePlain($value, $data, $settings));
				$metadata[$name] = $token_service->replacePlain($value, $data, $settings);
			}

			array_push($to_encode, $metadata);
			if ($this->debug_settings['language_toggle']) {
				break;
			}
		}
	}

	/**
	 * Harvests metadata from media entities
	 *
	 * @param $medium
	 *   Media entity to be harvested
	 *
	 * @param String $current_path
	 *   Current path inside staging directory
	 *
	 * @param Array &$to_encode
	 *   Stack variable with harvested metadata
	 *
	 * @param String $dir_url
	 *   URL of staging directory
	 *
	 * @param $update_mode
	 *   Indicator of deletion
	 */
	private function harvestMedia($medium, String $current_path, Array &$to_encode, String $dir_url, $update_mode)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		#$file_url = $file->createFileUrl();
		$file_uri = $file->getFileUri();
		$this->filesystem->copy($file_uri, $dir_url . "/". $current_path . '/' . $file->getFilename(), FileSystemInterface::EXISTS_REPLACE);
		//\Drupal::logger('digitalia_ltp_adapter')->debug();
		dpm($medium->bundle());

		$this->entityExtractMetadata($medium, $current_path, $to_encode, $dir_url, $file->getFilename(), $update_mode);
	}

	/**
	 * Starts ingest in archivematica, logs UUID of transfer
	 *
	 * @param $directory
	 *   Object directory to be ingested
	 */
	public function startIngestArchivematica(String $directory)
	{
		dpm("startIngest: start");
		\Drupal::logger('digitalia_ltp_adapter')->debug("startIngest: start");

		$transfer_uuid = $this->startTransfer($directory);

		if ($transfer_uuid) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("startIngest: transfer with uuid: '$transfer_uuid' started!");
			$this->waitForTransferCompletion($transfer_uuid);
			dpm("startIngest: transfer completed!");
			\Drupal::logger('digitalia_ltp_adapter')->debug("startIngest: transfer with uuid: '$transfer_uuid' completed!");
			return;
		}
	}

	/**
	 * Blocks until a transfer is finished, logs unsuccessful transfers
	 *
	 * @param String $transfer_uuid
	 *   uuid to wait for
	 */
	private function waitForTransferCompletion(String $transfer_uuid)
	{
		$am_host = $this->config->get('am_host');
		$username = $this->config->get('api_key_username');
		$password = $this->config->get('api_key_password');
		$status = "";
		$response = "";

		$client = \Drupal::httpClient();

		while ($status != "COMPLETE") {
			\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: loop started");
			sleep(1);
			try {
				$response = $client->request('GET', $am_host . '/api/transfer/status/' . $transfer_uuid, ['headers' => ['Authorization' => 'ApiKey ' . $username . ":" . $password, 'Host' => "dirk.localnet"]]);
				$status = json_decode($response->getBody()->getContents(), TRUE)["status"];
				\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: status = " . $status);
			} catch (\Exception $e) {
				dpm($e->getMessage());
				return false;
			}

			if ($status == "FAILED" || $status == "REJECTED" || $status == "USER_INPUT") {
				\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: status = " . $status);
			}
		}

		\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: transfer completed");
	}

	/**
	 * Starts transfer of selected directories to Archivematica
	 *
	 * @param String $directory
	 *   Directory, which is to be ingested
	 *
	 * @return String
	 *   Transfer UUID
	 */
	private function startTransfer(String $directory)
	{
		if (!file_exists($this->config->get('base_url') . "/" . $directory . "/metadata/metadata.json")) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("No metadata.json found. Transfer aborted.");
			return;
		}

		\Drupal::logger('digitalia_ltp_adapter')->debug("Zipping directory $directory");
		$zip_file = $this->zipDirectory($directory);

		$pathdir = $this->config->get('base_url') . "/";
		# delete source directory
		$this->filesystem->deleteRecursive($pathdir . $directory);

		# save hash of zip file
		$hash = hash_file("sha512", $pathdir . $zip_file);
		$this->file_repository->writeData("Sha512 " . $hash, $this->config->get('base_url') . "/" . $directory . ".sums", FileSystemInterface::EXISTS_REPLACE);

		dpm($hash);


		dpm("Sending request...");

		$am_host = $this->config->get('am_host');
		$username = $this->config->get('api_key_username');
		$password = $this->config->get('api_key_password');

		$client = \Drupal::httpClient();

		dpm($this->config->get('base_url'));

		$path = "/archivematica/drupal/" . $zip_file;
		$transfer_name = transliterator_transliterate('Any-Latin;Latin-ASCII;', $zip_file);

		$ingest_params = array('path' => base64_encode($path), 'name' => $transfer_name, 'processing_config' => 'automated', 'type' => 'zipfile');
		try {
			$response = $client->request('POST', $am_host . '/api/v2beta/package', ['headers' => ['Authorization' => 'ApiKey ' . $username . ":" . $password, 'ContentType' => 'application/json'], 'body' => json_encode($ingest_params)]);
			return json_decode($response->getBody()->getContents(), TRUE)["id"];
		} catch (\Exception $e) {
			dpm($e->getMessage());
			\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: " . $e->getMessage());
			return false;
		}
	}


	/**
	 * Removes all generated files from previous exports
	 *
	 * @param $entity
	 *   Entity to be exported
	 */
	public function preExportCleanup($entity)
	{
		$filename = $this->getFullEntityUID($entity);
		$filepath = $this->config->get('base_url') . "/" . $filename;

		$this->filesystem->deleteRecursive($filepath);
	}

	private function zipDirectory(String $directory)
	{
		$pathdir = $this->config->get('base_url') . "/";
		$zip_file = $directory . ".zip";

		# zip file must exist
		fopen($this->filesystem->realpath($pathdir . $zip_file), "w");

		dpm($pathdir);
		dpm($zip_file);
		dpm($this->filesystem->realpath($pathdir));

		$zip = \Drupal::service('plugin.manager.archiver')->getInstance(['filepath' => $pathdir . $zip_file])->getArchive();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($pathdir . $directory),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ($files as $file) {
			if (!$file->isDir()) {
				$file_path = $this->filesystem->realpath($file);
				$relative_path = substr($file_path, strlen($this->filesystem->realpath($pathdir)) + 1);
				
				$zip->addFile($file_path, $relative_path);
			}
		}

		return $directory . ".zip";
	}


	/**
	 * DEBUG SECTION
	 */

	public function printFieldConfig()
	{
		dpm($this->content_types_fields);
	}

}
