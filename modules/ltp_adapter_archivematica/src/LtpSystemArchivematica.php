<?php

namespace Drupal\digitalia_ltp_adapter_archivematica;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;
use Drupal\digitalia_ltp_adapter\LtpSystemInterface;
use Drupal\digitalia_ltp_adapter\Utils;
use Drupal\digitalia_ltp_adapter\MetadataExtractor;

class LtpSystemArchivematica implements LtpSystemInterface
{
	private $config;
	private $host;
	private $username;
	private $password;
	private $base_url;
	private $directory;


	public function __construct()
	{
		$this->config = \Drupal::config('digitalia_ltp_adapter_archivematica.settings');
		$this->host = $this->config->get('am_host');
		$this->username = $this->config->get('api_key_username');
		$this->password = $this->config->get('api_key_password');
		$this->base_url = $this->config->get('base_url');
	}

	public function getBaseUrl()
	{
		return $this->base_url;
	}

	public function getDirectory()
	{
		return $this->directory;
	}

	public function getConfig()
	{
		return $this->config;
	}

	public function getName()
	{
		return "Archivematica";
	}

	public function setDirectory(String $directory)
	{
		$this->directory = $directory;
	}

	/**
	 * {@inheritdoc}
	 */
	public function writeSIP($entity, Array $metadata, Array $file_uri)
	{
		$utils = new Utils();
		$dirpath = $this->getBaseUrl() . "/" . $this->getDirectory();

		$filesystem = \Drupal::service('file_system');
		$file_repository = \Drupal::service('file.repository');

		if (!$utils->checkAndLock($dirpath)) {
			\Drupal::logger('digitalia_ltp_adapter_archivematica')->debug("Couldn't obtain lock for directory '$directory', pre cleanup");
			return;
		}

		$utils->removeFromQueue($this->getDirectory());
		$utils->preExportCleanup($entity, $this->getBaseUrl());

		if (!$utils->checkAndLock($dirpath)) {
			\Drupal::logger('digitalia_ltp_adapter_archivematica')->debug("Couldn't obtain lock for directory '$directory', post cleanup");
			return;
		}

		$dir_metadata = $dirpath . "/metadata";
		$dir_objects = $dirpath . "/objects";
		$dummy_filenames = array();

		// Add dummy filenames
		foreach ($metadata as &$section) {
			$section["filename"] = "objects/" . $section["export_language"] . ".txt";
			array_push($dummy_filenames, $section["filename"]);
		}

		$encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES);

		try {
			$filesystem->prepareDirectory($dir_metadata, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
			$filesystem->prepareDirectory($dir_objects, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

			$file_repository->writeData($encoded, $dir_metadata . "/metadata.json" , FileSystemInterface::EXISTS_REPLACE);

			if ($file_uri[0] != "") {
				$filesystem->copy($file_uri[0], $dir_objects . "/". $file_uri[1], FileSystemInterface::EXISTS_REPLACE);
			}

			foreach ($dummy_filenames as $_value => $filepath) {
				$file_repository->writeData("", $dirpath . "/" . $filepath, FileSystemInterface::EXISTS_REPLACE);
			}

		} catch (\Exception $e) {
			\Drupal::logger('digitalia_ltp_adapter_archivematica')->error($e->getMessage());
			$utils->removeLock($dirpath);
			return;
		}

		$utils->removeLock($dirpath);

		$utils->addToQueue(array(
			'directory' => $this->getDirectory(),
			'entity_type' => $entity->getEntityTypeId(),
			'uuid' => $entity->uuid(),
			'fields' => [
				'transfer_uuid' => $this->config->get("transfer_field"),
				'sip_uuid' => $this->config->get("sip_field"),
			],
		));
	}

	/**
	 * {@inheritdoc}
	 */
	public function startIngest()
	{
		$transfer_uuid = $this->startTransfer($this->getBaseUrl());

		if ($transfer_uuid) {
			$sip_uuid = $this->waitForTransferCompletion($transfer_uuid);

			if (!$this->waitForIngestCompletion($sip_uuid)) {
				\Drupal::logger('digitalia_ltp_adapter_archivematica')->debug("Ingest with SIP uuid: '$sip_uuid' failed!");
				return;
			}

			$result = [
				'transfer_uuid' => $transfer_uuid,
				'sip_uuid' => $sip_uuid,
			];

			return $result;
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
		$status = "";
		$response = "";

		$client = \Drupal::httpClient();

		while ($status != "COMPLETE") {
			\Drupal::logger('digitalia_ltp_adapter_archivematica')->debug("waitForTransferCompletion: loop started");
			sleep(1);
			try {
				$response = $client->request(
					'GET',
					$this->host . '/api/transfer/status/' . $transfer_uuid, [
						'headers' => [
							'Authorization' => 'ApiKey ' . $this->username . ":" . $this->password,
							'Host' => "dirk.localnet",
						]
					]
				);

				$body = json_decode($response->getBody()->getContents(), TRUE);
				$status = $body["status"];

				if ($status == "COMPLETE") {
					return $body["sip_uuid"];
				}

			} catch (\Exception $e) {
				return false;
			}

			if ($status == "FAILED" || $status == "REJECTED" || $status == "USER_INPUT") {
				\Drupal::logger('digitalia_ltp_adapter_archivematica')->error("waitForTransferCompletion: status = " . $status);
				return false;
			}
		}
	}

	/**
	 * Blocks until a ingest is finished, logs unsuccessful ingests
	 *
	 * @param String $sip_uuid
	 *   uuid to wait for
	 */
	private function waitForIngestCompletion(String $sip_uuid)
	{
		$status = "";
		$client = \Drupal::httpClient();

		while ($status != "COMPLETE") {
			sleep(1);
			try {
				$response = $client->request(
					'GET',
					$this->host . '/api/ingest/status/' . $sip_uuid,
					['headers' => [
						'Authorization' => 'ApiKey ' . $this->username . ":" . $this->password,
						]
					]
				);

				$status = json_decode($response->getBody()->getContents(), TRUE)["status"];
				\Drupal::logger('digitalia_ltp_adapter_archivematica')->debug("waitForIngestCompletion: status = " . $status);

			} catch (\Exception $e) {
				return false;
			}

			if ($status == "FAILED" || $status == "REJECTED" || $status == "USER_INPUT") {
				\Drupal::logger('digitalia_ltp_adapter_archivematica')->debug("waitForIngestCompletion: status = " . $status);
				return false;
			}
		}

		return true;
	}

	/**
	 * Starts transfer of selected directories to Archivematica
	 *
	 * @param String $dirpath
	 *   Directory, which is to be ingested
	 *
	 * @return String
	 *   Transfer UUID
	 */
	private function startTransfer()
	{
		$dirpath = $this->getBaseUrl(). "/" . $this->getDirectory();
		if (!file_exists($dirpath . "/metadata/metadata.json")) {
			\Drupal::logger('digitalia_ltp_adapter_archivematica')->debug("No metadata.json found. Transfer aborted.");
			return;
		}

		$utils = new Utils();

		$zip_file = $utils->zipDirectory($this->getBaseUrl(), $this->getDirectory(), false);

		// delete source directory
		\Drupal::service('file_system')->deleteRecursive($dirpath);

		$client = \Drupal::httpClient();

		$path = $this->getConfig()->get("am_shared_path") . "/" . $zip_file;

		$ingest_params = array(
			'path' => base64_encode($path),
			'name' => $this->getDirectory(),
			'processing_config' => $this->getConfig()->get("processing_config"),
			'type' => 'zipfile',
		);

		try {
			$response = $client->request(
				'POST',
				$this->host . '/api/v2beta/package', [
					'headers' => [
						'Authorization' => 'ApiKey ' . $this->username . ":" . $this->password,
						'ContentType' => 'application/json',
						],
					'body' => json_encode($ingest_params)
				]
			);

			return json_decode($response->getBody()->getContents(), TRUE)["id"];
		} catch (\Exception $e) {
			\Drupal::logger('digitalia_ltp_adapter_archivematica')->debug("startTransfer(): " . $e->getMessage());
			return false;
		}
	}
}

