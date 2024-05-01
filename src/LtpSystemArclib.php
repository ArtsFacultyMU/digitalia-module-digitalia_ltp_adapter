<?php

namespace Drupal\digitalia_ltp_adapter;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileSystem;

class LtpSystemArclib implements LtpSystemInterface
{
	private $config;


	public function __construct()
	{
		$this->config = \Drupal::config('digitalia_ltp_adapter.admin_settings');
	}

	/**
	 * Starts ingest in ARCLib, logs UUID of transfer
	 *
	 * @param $directory
	 *   Object directory to be ingested
	 */
	public function startIngest(String $directory)
	{
		dpm("startIngest: start");
		\Drupal::logger('digitalia_ltp_adapter')->debug("startIngest: start");

		$transfer_uuid = $this->startTransfer($directory);

		if ($transfer_uuid) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("startIngest: transfer with uuid: '$transfer_uuid' started!");
			$this->waitForTransferCompletion($transfer_uuid);
			dpm("startIngest: transfer completed!");
			\Drupal::logger('digitalia_ltp_adapter')->debug("startIngest: transfer with uuid: '$transfer_uuid' completed!");
			return $transfer_uuid;
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
		$ltp_host = $this->config->get('ltp_host');
		$username = $this->config->get('api_key_username');
		$password = $this->config->get('api_key_password');
		$status = "";
		$response = "";

		$client = \Drupal::httpClient();

		while ($status != "COMPLETE") {
			\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: loop started");
			sleep(1);
			try {
				$response = $client->request('GET', $ltp_host . '/api/transfer/status/' . $transfer_uuid, ['headers' => ['Authorization' => 'ApiKey ' . $username . ":" . $password, 'Host' => "dirk.localnet"]]);
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
	 * Starts transfer of selected directories to ARCLib
	 *
	 * @param String $directory
	 *   Directory, which is to be ingested
	 *
	 * @return String
	 *   Transfer UUID
	 */
	private function startTransfer(String $directory)
	{
		if (!file_exists($this->config->get('base_url') . "/" . $directory . "/metadata/metadata.xml")) {
			\Drupal::logger('digitalia_ltp_adapter')->debug("No metadata.xml found. Transfer aborted.");
			return;
		}

		$utils = new DigitaliaLtpUtils();

		\Drupal::logger('digitalia_ltp_adapter')->debug("Zipping directory $directory");
		$zip_file = $utils->zipDirectory($directory);

		$pathdir = $this->config->get('base_url') . "/";
		# delete source directory
		$utils->filesystem->deleteRecursive($pathdir . $directory);

		# save hash of zip file
		$hash = hash_file("sha512", $pathdir . $zip_file);
		$utils->file_repository->writeData("Sha512 " . $hash, $this->config->get('base_url') . "/" . $directory . ".sums", FileSystemInterface::EXISTS_REPLACE);


		dpm("Sending request...");

		//$ltp_host = $this->config->get('ltp_host');
		//$username = $this->config->get('api_key_username');
		//$password = $this->config->get('api_key_password');

		$ltp_host = "http://arclib.localnet"
		$username = "arclibadmin"
		$password = "password"

		$client = \Drupal::httpClient();

		dpm($this->config->get('base_url'));

		$path = "/archivematica/drupal/" . $zip_file;
		$transfer_name = transliterator_transliterate('Any-Latin;Latin-ASCII;', $zip_file . "_" . time());

		$ingest_params = array('path' => base64_encode($path), 'name' => $transfer_name, 'processing_config' => 'automated', 'type' => 'zipfile');
		try {
			$response = $client->request('POST', $ltp_host . '/api/v2beta/package', ['headers' => ['Authorization' => 'ApiKey ' . $username . ":" . $password, 'ContentType' => 'application/json'], 'body' => json_encode($ingest_params)]);
			return json_decode($response->getBody()->getContents(), TRUE)["id"];
		} catch (\Exception $e) {
			dpm($e->getMessage());
			\Drupal::logger('digitalia_ltp_adapter')->debug("waitForTransferCompletion: " . $e->getMessage());
			return false;
		}
	}
}


