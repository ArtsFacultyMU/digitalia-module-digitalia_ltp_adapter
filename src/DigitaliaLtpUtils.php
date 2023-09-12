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
	private $filesystem;
	private $file_repository;
	private $entity_manager;
	private $languages;

	private $DIRECTORY;
	private $DIRECTORY_URL;
	private $METADATA_PATH;
	private $REST_CREDENTIALS;

	public function __construct()
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->entity_manager = \Drupal::entityTypeManager();
		$this->languages = \Drupal::languageManager()->getLanguages();
		// TODO: change to constants
		$this->REST_CREDENTIALS = "test:test";
		$this->METADATA_PATH = "metadata/metadata.json";
		$this->DIRECTORY = "digitalia_ltp";
		$this->DIRECTORY_URL = "public://archivematica/archivematica/" . $this->DIRECTORY;
		//$this->DIRECTORY_URL = "public://digitalia_ltp_metadata";

	}

	public function archiveData($node)
	{
		dpm("Preparing data...");
		$to_encode = array();
		$current_path = "objects";


		$dir_metadata = $this->DIRECTORY_URL . "/metadata";
		$dir_objects = $this->DIRECTORY_URL . "/objects";

		$this->filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$this->filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);



		$this->harvestMetadata($node, $current_path, $to_encode);

		$encoded = json_encode($to_encode, JSON_UNESCAPED_SLASHES);


		dpm($encoded);

		$this->file_repository->writeData($encoded, $this->DIRECTORY_URL . "/" . $this->METADATA_PATH, FileSystemInterface::EXISTS_REPLACE);

		//dpm(transliterator_transliterate('Any-Latin;Latin-ASCII;', $title));
		//foreach ($fields as $name => $field) {
		//	dpm("$name:" . $field->getString());
		//}
		dpm("Data prepared!");

	}

	public function startIngest()
	{
		dpm("Starting archivation process...");

		$this->startTransfer(array());


		dpm("Archivation proces done!");
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
	 */
	private function harvestMetadata($node, String $base_path, Array &$to_encode)
	{
		dpm("Entity type id: " . $node->getEntityTypeId());

		$current_path = $base_path. "/" . $node->getTitle();
		$dir_path = $this->DIRECTORY_URL . "/". $current_path;
		//$dir_path = $this->DIRECTORY_URL . "/". $current_path . "/" . $node->getTitle();
		$filesystem = $this->filesystem->prepareDirectory($dir_path, FileSystemInterface::CREATE_DIRECTORY |
											       FileSystemInterface::MODIFY_PERMISSIONS);
		$children = $this->entity_manager->getStorage('node')->loadByProperties(['field_member_of' => $node->id()]);
		$media = $this->entity_manager->getStorage('media')->loadByProperties(['field_media_of' => $node->id()]);


		$this->entityExtractMetadata($node, $current_path, $to_encode);

		foreach($children as $child) {
			$this->harvestMetadata($child, $base_path, $to_encode);
		}

		foreach($media as $medium) {
			$this->harvestMedia($medium, $current_path, $to_encode);
		}
	}

	private function entityExtractMetadata($entity, String $current_path, Array &$to_encode, String $filename = null)
	{
		foreach ($this->languages as $lang => $_value) {
			$entity_translated = \Drupal::service('entity.repository')->getTranslationFromContext($entity, $lang);

			if (!$filename) {
				$filepath = $current_path . "/" . $lang . ".txt";
				$this->file_repository->writeData("", $this->DIRECTORY_URL . '/' . $filepath, FileSystemInterface::EXISTS_REPLACE);
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

	private function harvestMedia($medium, String $current_path, Array &$to_encode)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		$file_url = $file->createFileUrl();
		$file_uri = $file->getFileUri();
		$this->filesystem->copy($file_uri, $this->DIRECTORY_URL . "/". $current_path . '/' . $file->getFilename(), FileSystemInterface::EXISTS_REPLACE);

		$this->entityExtractMetadata($medium, $current_path, $to_encode, $file->getFilename());
	}


	private function startTransfer(Array $metadata)
	{
		dpm("Sending request...");

		$am_host = "dirk.localnet:62080";

		$client = \Drupal::httpClient();
		$path = "/archivematica/" . $this->DIRECTORY;
		$transfer_name = "test_transfer_02";
		//$response = $client->request('GET', 'http://dirk.localnet:62080/api/transfer/unapproved/', ['headers' => ['Authorization' => 'ApiKey test:test', 'Content-Type' => 'application/x-www-form-urlencoded']]);
		////dpm($response);
		////dpm($response->getBody());
		//dpm(json_decode($response->getBody()->getContents(), TRUE));


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
