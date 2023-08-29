<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\node\Entity\Node;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;

class DigitaliaLtpUtils
{
	private $filesystem;
	private $file_repository;
	private $entity_manager;

	private $DIRECTORY;
	private $METADATA_PATH;

	public function __construct()
	{
		$this->filesystem = \Drupal::service('file_system');
		$this->file_repository = \Drupal::service('file.repository');
		$this->entity_manager = \Drupal::entityTypeManager();
		$this->METADATA_PATH = "metadata/metadata.json";
		$this->DIRECTORY = "public://digitalia_ltp_metadata_only";

	}

	public function prepareData($node)
	{
		dpm("Preparing data...");
		$fields = $node->getFields();
		$title = $node->getTitle();
		$filename = 'object/' . $title;
		$to_encode = array();
		$current_path = "objects";


		$dir_metadata = $this->DIRECTORY . "/metadata"; 
		$dir_objects = $this->DIRECTORY . "/objects";

		$this->filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
		$this->filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);



		$this->harvestMetadata($node, $current_path, $to_encode);

		$encoded = json_encode($to_encode, JSON_UNESCAPED_SLASHES);


		dpm($encoded);

		$this->file_repository->writeData($encoded, $this->DIRECTORY . "/" . $this->METADATA_PATH, FileSystemInterface::EXISTS_REPLACE);

		//dpm(transliterator_transliterate('Any-Latin;Latin-ASCII;', $title));
		//foreach ($fields as $name => $field) {
		//	dpm("$name:" . $field->getString());
		//}
		dpm("Data prepared!");
	}

	/**
	 * Appends metadata of all descendants of a node
	 *
	 * @param object $node
	 *   A core drupal node object
	 *
	 * @param String $current_path
	 *   Tracks the path
	 * 
	 * @param Array $to_encode
	 *   For appending metadata
	 */
	private function harvestMetadata($node, String $current_path, Array &$to_encode)
	{
		$current_path = $current_path . "/" . $node->getTitle();
		$dir_path = $this->DIRECTORY . "/". $current_path;
		$filesystem = $this->filesystem->prepareDirectory($dir_path, FileSystemInterface::CREATE_DIRECTORY |
											       FileSystemInterface::MODIFY_PERMISSIONS);
		$filename = $current_path . "/dummy.txt";
		$this->file_repository->writeData("", $this->DIRECTORY . '/' . $filename, FileSystemInterface::EXISTS_REPLACE);
		//$metadata = array('filename' => $filename, 'id' => $node->id(), 'title' => $node->getTitle());
		$metadata = array('filename' => $filename);
		$children = $this->entity_manager->getStorage('node')->loadByProperties(['field_member_of' => $node->id()]);
		$media = $this->entity_manager->getStorage('media')->loadByProperties(['field_media_of' => $node->id()]);
		foreach($children as $child) {
			$this->harvestMetadata($child, $current_path, $to_encode);
		}

		foreach($media as $medium) {
			//dpm("Harvesting media");
			$this->harvestMedia($medium, $current_path, $to_encode);
		}

		dpm($metadata);
		foreach ($node->getFields(false) as $name => $field) {
			$type = $node->get($name)->getFieldDefinition()->getType();
			//dpm($type);

			if ($type == 'entity_reference') {
				dpm("REFERENCE FOUND");
				foreach($node->get($name)->referencedEntities() as $object) {
					dpm($object->label());
				}
			}
			$metadata[$name] = $field->getString();
			//if ($name == "field_object_type_basic") {
			//	//dpm(\Drupal\taxonomy\Entity\Term::load($field->getString())->getName());
			//	dpm($node->get($name)->referencedEntities());
			//}
			//if ($name == "uid") {
			//	//dpm(\Drupal\taxonomy\Entity\Term::load($field->getString())->getName());
			//	dpm($node->get($name)->referencedEntities());
			//}

		}

		array_push($to_encode, $metadata);
	}

	private function harvestMedia($medium, String $current_path, Array &$to_encode)
	{
		$fid = $medium->getSource()->getSourceFieldValue($medium);
		$file = File::load($fid);
		$file_url = $file->createFileUrl();
		$file_uri = $file->getFileUri();
		$this->filesystem->copy($file_uri, $this->DIRECTORY . "/". $current_path . '/' . $file->getFilename(), FileSystemInterface::EXISTS_REPLACE);
	}

}
