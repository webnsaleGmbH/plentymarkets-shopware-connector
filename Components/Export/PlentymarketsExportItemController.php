<?php
/**
 * plentymarkets shopware connector
 * Copyright © 2013 plentymarkets GmbH
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License, supplemented by an additional
 * permission, and of our proprietary license can be found
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "plentymarkets" is a registered trademark of plentymarkets GmbH.
 * "shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, titles and interests in the
 * above trademarks remain entirely with the trademark owners.
 *
 * @copyright  Copyright (c) 2013, plentymarkets GmbH (http://www.plentymarkets.com)
 * @author     Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */

require_once PY_COMPONENTS . 'Export/PlentymarketsExportController.php';
require_once PY_COMPONENTS . 'Export/Entity/PlentymarketsExportEntityItem.php';

/**
 * The class PlentymarketsExportItemController handles the item export.
 *
 * @author Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */
class PlentymarketsExportItemController
{
	/**
	 * PlentymarketsExportItemController object data.
	 *
	 * @var PlentymarketsExportItemController
	 */
	protected static $Instance;
	
	/**
	 * PlentymarketsConfig object data.
	 *
	 * @var PlentymarketsConfig
	 */
	protected $Config;
	
	/**
	 * 
	 * @var integer
	 */
	protected $currentChunk = 0;
	
	/**
	 * 
	 * @var integer
	 */
	protected $maxChunks;
	
	/**
	 * 
	 * @var integer
	 */
	protected $chunksDone = 0;
	
	/**
	 * 
	 * @var integer
	 */
	protected $sizeOfChunk;
	
	/**
	 * 
	 * @var boolean
	 */
	protected $toBeContinued = false;

	/**
	 * Prepares config data and checks different conditions like finished mapping.
	 */
	protected function __construct()
	{
		// Config
		$this->Config = PlentymarketsConfig::getInstance();

		// 
		$this->configure();
	}
	
	/**
	 * Sets the current status
	 */
	protected function destruct()
	{
		// Set running
		if (!$this->toBeContinued)
		{
			$this->Config->setItemExportTimestampFinished(time());
			$this->Config->setItemExportStatus('success');
				
			// Reset values
			$this->Config->setImportItemLastUpdateTimestamp(0);
			$this->Config->setImportItemPriceLastUpdateTimestamp(0);
			$this->Config->setImportItemStockLastUpdateTimestamp(0);
		}
		else
		{
			PlentymarketsLogger::getInstance()->message('Export:Initial:Item', 'Stopping. I will continue with the next run.');
				
			$this->Config->setExportEntityPending('Item');
			$this->Config->setItemExportStatus('pending');
		}
	}

	/**
	 * If an instance of PlentymarketsExportItemController exists, it returns this instance.
	 * Else it creates a new instance of PlentymarketsExportController.
	 *
	 * @return PlentymarketsExportItemController
	 */
	public static function getInstance()
	{
		if (!self::$Instance instanceof self)
		{
			self::$Instance = new self();
		}
		return self::$Instance;
	}
	
	/**
	 * Runs the actual export of the items
	 */
	public function run()
	{
		// Set running
		$this->Config->setItemExportStatus('running');
		
		// Starttime
		if ($this->currentChunk == 0)
		{
			$this->Config->setItemExportTimestampStart(time());
		}
		
		// Export
		$this->export();
		
		// Finish
		$this->destruct();
	}
	
	/**
	 * Configures the chunk settings
	 */
	protected function configure()
	{
		// Check for a previous chunk
		$lastChunk = $this->Config->getItemExportLastChunk();
		
		if ($lastChunk > 0)
		{
			$this->currentChunk = $lastChunk + 1;
		}
		
		// Max. number of chunks per run
		$this->maxChunks = (integer) $this->Config->getInitialExportChunksPerRun(PlentymarketsExportController::DEFAULT_CHUNKS_PER_RUN);
		
		// Items per chunki
		$this->sizeOfChunk = (integer) PlentymarketsConfig::getInstance()->getInitialExportChunkSize(PlentymarketsExportController::DEFAULT_CHUNK_SIZE);
	}


	/**
	 * Exports images, variants, properties item data and items base to make sure, that the corresponding items data exist.
	 */
	protected function export()
	{
		// Query builder	
		$QueryBuilder = Shopware()->Models()->createQueryBuilder();
		$QueryBuilder
			->select('item.id')
			->from('Shopware\Models\Article\Article', 'item');

		do {
			
			// Log the chunk
			PlentymarketsLogger::getInstance()->message('Export:Initial:Item', 'Chunk: '. ($this->currentChunk + 1));
			
			// Set Limit and Offset
			$QueryBuilder
				->setFirstResult($this->currentChunk * $this->sizeOfChunk)
				->setMaxResults($this->sizeOfChunk);
			
			// Get the items
			$items = $QueryBuilder->getQuery()->getArrayResult();

			foreach ($items as $item)
			{
				try
				{
					// If there is a plenty id for this shopware id,
					// the item has already been exported to plentymarkets
					PlentymarketsMappingController::getItemByShopwareID($item['id']);
					
					// already done
					continue;
				}
				catch (PlentymarketsMappingExceptionNotExistant $E)
				{
				}
				
				$PlentymarketsExportEntityItem = new PlentymarketsExportEntityItem(
					Shopware()->Models()->find('Shopware\Models\Article\Article', $item['id'])
				);
					
				$PlentymarketsExportEntityItem->export();
			}
			
			// Remember the chunk
			$this->Config->setItemExportLastChunk($this->currentChunk);
			
			// Quit when the maximum number of chunks is reached
			if ($this->maxChunks > 0 && ++$this->chunksDone >= $this->maxChunks)
			{
				$this->toBeContinued = true;
				break;
			}
			
			// Next chunk
			++$this->currentChunk;
			
		} while (!empty($items) && count($items) == $this->sizeOfChunk);
	}
}
