<?php

namespace Drupal\digitalia_ltp_adapter;

interface LtpSystemInterface
{
	/**
	 * Starts ingest into LTP system
	 *
	 * @param $directory
	 *   Object directory to be ingested
	 */
	public function startIngest();
}
