<?php

namespace Drupal\digitalia_ltp_adapter\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides test block with button generating metadata
 *
 * @Block(
 *   id = "digitalia_generate_metadata_block",
 *   admin_label = @Translation("Generate metadata for node")
 *   )
 */
class GenerateMetadataBlock extends BlockBase
{
	/**
	 * {@inheritdoc}
	 */
	public function build()
	{
		return \Drupal::formBuilder()->getForm('Drupal\digitalia_ltp_adapter\Form\GenerateMetadataForm');
	}
}
