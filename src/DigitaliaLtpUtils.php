<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;


class DigitaliaLtpUtils
{
	private $directories;


	private $filesystem;
	private $file_repository;
	private $entity_manager;
	private $languages;

	private $BASE_URL;
	private $METADATA_PATH;
	private $REST_CREDENTIALS;

	const Flat = 0;
	const Tree = 1;
	const Single = 2;
	const Separate = 3;

	public function __construct()
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->entity_manager = \Drupal::entityTypeManager();
		$this->languages = \Drupal::languageManager()->getLanguages();
		$this->directories = array();
		// TODO: change to constants
		$this->REST_CREDENTIALS = "test:test";
		$this->METADATA_PATH = "metadata/metadata.json";
		$this->BASE_URL = "public://archivematica/archivematica/";

	}

	/**
	 * @return array
	 *   Array of directories prepared for ingest
	 */
	public function archiveData($node, $export_mode)
	{
		dpm("Preparing data...");
		$this->archiveSourceNode($node, $export_mode, $node->getTitle());
		dpm("Data prepared!");

		return $this->directories;
	}

	private function archiveSourceNode($node, $export_mode, String $directory)
	{
		dpm("Preparing data...");
		$to_encode = array();
		$current_path = "objects";
		array_push($this->directories, $directory);


		$dir_url = $this->BASE_URL . "/" . $directory;
		$dir_metadata = $dir_url . "/metadata";
		$dir_objects = $dir_url . "/objects";

		$this->filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$this->filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);



		$this->harvestMetadata($node, $current_path, $to_encode, $export_mode, $dir_url);

		$encoded = json_encode($to_encode, JSON_UNESCAPED_SLASHES);


		dpm($encoded);

		$this->file_repository->writeData($encoded, $dir_url . "/" . $this->METADATA_PATH, FileSystemInterface::EXISTS_REPLACE);

		//dpm(transliterator_transliterate('Any-Latin;Latin-ASCII;', $title));
		//foreach ($fields as $name => $field) {
		//	dpm("$name:" . $field->getString());
		//}
		dpm("Data prepared!");


	}

	/**
	 * Starts ingest in archivematica
	 *
	 * @param #$directories
	 *   Directories to be ingested
	 */
	public function startIngest(array $directories)
	{
		dpm("Starting archivation process...");

		$this->startTransfer($directories);


		dpm("Archivation proces started!");
	}


	/**
	 * Appends metadata of all descendants of a entity
	 *
	 * @param object $node
	 *   A drupal entity
	 *
	 * @param String $base_path
	 *   Base path of objects
	 *
	 * @param Array $to_encode
	 *   For appending metadata
	 *
	 * @param $export_mode
	 *   Determines export mode
	 */
	private function harvestMetadata($node, String $base_path, Array &$to_encode, $export_mode, String $dir_url)
	{
		dpm("Entity type id: " . $node->getEntityTypeId());

		$current_path = $base_path. "/" . $node->getTitle();
		$dir_path = $dir_url . "/". $current_path;
		$filesystem = $this->filesystem->prepareDirectory($dir_path, FileSystemInterface::CREATE_DIRECTORY |
											       FileSystemInterface::MODIFY_PERMISSIONS);
		$children = $this->entity_manager->getStorage('node')->loadByProperties(['field_member_of' => $node->id()]);
		$media = $this->entity_manager->getStorage('media')->loadByProperties(['field_media_of' => $node->id()]);


		$this->entityExtractMetadata($node, $current_path, $to_encode, $dir_url, null);


		foreach($media as $medium) {
			//dpm($current_path);
			//dpm($dir_url);
			$this->harvestMedia($medium, $current_path, $to_encode, $dir_url);
		}

		if ($export_mode == $this::Flat) {
			foreach($children as $child) {
				$this->harvestMetadata($child, $base_path, $to_encode, $export_mode, $dir_url);
			}
		}

		if ($export_mode == $this::Tree) {
			foreach($children as $child) {
				$this->harvestMetadata($child, $current_path, $to_encode, $export_mode, $dir_url);
			}
		}

		if ($export_mode == $this::Separate) {
			foreach($children as $child) {
				$this->archiveData($child, $export_mode);
			}
		}

	}

	private function entityExtractMetadata($entity, String $current_path, Array &$to_encode, String $dir_url, String $filename)
	{
		foreach ($this->languages as $lang => $_value) {
			$entity_translated = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $lang);

			if (!$filename) {
				$filepath = $current_path . "/" . $lang . ".txt";
				$this->file_repository->writeData("", $dir_url . '/' . $filepath, FileSystemInterface::EXISTS_REPLACE);
			} else {
				$filepath = $current_path . "/" . $filename;
			}

			$metadata = array('filename' => $filepath, 'export_language' => $lang);

			foreach ($entity_translated->getFields(false) as $name => $_value) {
				$type = $entity_translated->get($name)->getFieldDefinition()->getType();

				$field_array = $entity_translated->get($name)->getValue();
				$values = array();
				$values_label = array();
				if ($type == 'entity_reference') {
					foreach($entity_translated->get($name)->referencedEntities() as $object) {
						$translated = \Drupal::service('entity.repository')->getTranslationFromContext($object, $lang);

						array_push($values, $translated->id());
						array_push($values_label, $translated->label());
					}

					// TODO: figure out content type label translation
					if ($name != 'type') {
						$metadata[$name . "_label"] = $values_label;
					}
				} else {
					foreach($field_array as $field) {
						array_push($values, $field['value']);
					}
				}

				$metadata[$name] = $values;

			}

			array_push($to_encode, $metadata);
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
	 */
	private function harvestMedia($medium, String $current_path, Array &$to_encode, String $dir_url)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		$file_url = $file->createFileUrl();
		$file_uri = $file->getFileUri();
		$this->filesystem->copy($file_uri, $dir_url . "/". $current_path . '/' . $file->getFilename(), FileSystemInterface::EXISTS_REPLACE);
		//\Drupal::logger('digitalia_ltp_adapter')->debug();

		$this->entityExtractMetadata($medium, $current_path, $to_encode, $dir_url, $file->getFilename());
	}


	/**
	 * Starts transfer of selected directories to Archivematica
	 *
	 * @param Array $directories
	 *   Directories, which are to be ingested
	 */
	private function startTransfer(Array $directories)
	{
		dpm("Sending request...");

		$am_host = "dirk.localnet:62080";

		$client = \Drupal::httpClient();


		foreach($directories as $directory) {
			$path = "/archivematica/" . $directory;
			$transfer_name = transliterator_transliterate('Any-Latin;Latin-ASCII;', $directory);



			$ingest_params = array('path' => base64_encode($path), 'name' => $transfer_name, 'processing_config' => 'automated', 'type' => 'standard');
			try {
				$response = $client->request('POST', 'http://' . $am_host . '/api/v2beta/package', ['headers' => ['Authorization' => 'ApiKey ' . $this->REST_CREDENTIALS, 'ContentType' => 'application/json'], 'body' => json_encode($ingest_params)]);
				dpm(json_decode($response->getBody()->getContents(), TRUE));
			} catch (\Exception $e) {
				dpm($e->getMessage());
				return false;
			}
		}
	}

}
