<?php
class kEdgeCastUrlManager extends kUrlManager
{	
	/**
	 * @param flavorAsset $flavorAsset
	 * @return string
	 */
	public function getFlavorAssetUrl(flavorAsset $flavorAsset)
	{
		$url = parent::getFlavorAssetUrl($flavorAsset);
		
		// move version param to "behind" the flavor asset id
		$flavorIdStr = '/flavorId/'.$flavorAsset->getId();
		$url = str_replace($flavorIdStr, '', $url);
		$url = str_replace('serveFlavor', 'serveFlavor'.$flavorIdStr, $url);
        
		$syncKey = $flavorAsset->getSyncKey(flavorAsset::FILE_SYNC_FLAVOR_ASSET_SUB_TYPE_ASSET);
		$url = $this->addEcSeek($url, $syncKey);			
		return $url;
	}
	
	/**
	 * @param FileSync $fileSync
	 * @return string
	 */
	public function getFileSyncUrl(FileSync $fileSync)
	{
		$url = parent::getFileSyncUrl($fileSync);
		$syncKey = kFileSyncUtils::getKeyForFileSync($fileSync);
		$url = $this->addEcSeek($url, $syncKey);
		return $url;
	}
	
	
	private function addEcSeek($url, $syncKey)
	{
        if (!empty($this->seekFromTime))
        {
            // remove default seekFrom parameter
            $url = preg_replace('/seekFrom\/-?[0-9]*\/?/', '', $url);
            $url = rtrim($url, '/');
            
            // check if seekFromTime is set to something significant
            if ($this->seekFromTime > 0)
            {
                // check if flv or not 		    
        	    $notFlvFormat = ($this->extention && strtolower($this->extention) != 'flv') || ($this->containerFormat && strtolower($this->containerFormat) != 'flash video');
        	    
        	    if ($notFlvFormat) {
        	        // not flv - add ec_seek value in seconds
        	        $url .= '?ec_seek='.$this->seekFromTime;
        	    }
        	    else {
        	        // flv - add ec_seek value in bytes
        	        $url .= '?ec_seek='.$this->getSeekFromBytes(kFileSyncUtils::getLocalFilePathForKey($syncKey));
        	    }
            }
        }        
		return $url;
	}
	
}
