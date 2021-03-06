<?php
/**
 * ownCloud - Audios
 *
 * @author Sebastian Doell
 * @copyright 2015 sebastian doell sebastian@libasys.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Audios\Controller;

use \OCP\AppFramework\Controller;
use \OCP\AppFramework\Http\JSONResponse;
use \OCP\AppFramework\Http\TemplateResponse;
use \OCP\IRequest;

/**
 * Controller class for main page.
 */
class MusicController extends Controller {
	
	private $userId;
	private $l10n;
	private static $sortType='album';
	

	public function __construct($appName, IRequest $request, $userId, $l10n) {
		parent::__construct($appName, $request);
		$this -> userId = $userId;
		$this->l10n = $l10n;
	}
	/**
	*@PublicPage
	 * @NoCSRFRequired
	 * 
	 */
	public function getPublicAudioStream($file){
		$pToken  = $this->params('token');	
		if (!empty($pToken)) {
			$linkItem = \OCP\Share::getShareByToken($pToken);
			if (!(is_array($linkItem) && isset($linkItem['uid_owner']))) {
				exit;
			}
			// seems to be a valid share
			$rootLinkItem = \OCP\Share::resolveReShare($linkItem);
			$user = $rootLinkItem['uid_owner'];
		   
			// Setup filesystem
			\OCP\JSON::checkUserExists($user);
			\OC_Util::tearDownFS();
			\OC_Util::setupFS($user);
			$startPath = \OC\Files\Filesystem::getPath($linkItem['file_source']) ;
		  	if((string)$linkItem['item_type'] === 'file'){
				$filenameAudio=$startPath;
			}else{
				$filenameAudio=$startPath.'/'.urldecode($file);
			}
			
			\OC::$server->getSession()->close();
			
			$stream = new \OCA\Audios\AudioStream($filenameAudio,$user);
			$stream -> start();
		} 
	}


	/**
	*@NoAdminRequired
	 * @NoCSRFRequired
	 * 
	 * 
	 */
	public function getAudioStream(){
		
		$pFile = $this->params('file');
			
		$filename = urldecode($pFile);
		\OCP\JSON::checkLoggedIn();
		$user = $this->userId;
			
		\OC::$server->getSession()->close();

		$stream = new \OCA\Audios\AudioStream($filename,$user);
		$stream -> start();
			
	}


	/**
	 * @NoAdminRequired
	 * 
	 */
	public function getMusic(){
			
		$aSongs = $this->loadSongs();
    	$aAlbums = $this->loadAlbums();
		\OC::$server->getSession()->close();
		
		if(is_array($aAlbums)){
			$result=[
					'status' => 'success',
					'data' => ['albums'=>$aAlbums,'songs'=>$aSongs]
				];
		}else{
			$result=[
					'status' => 'success',
					'data' =>'nodata'
				];
		}
		
		$response = new JSONResponse();
		$response -> setData($result);
		return $response;
	}
	
	/**
	 * @NoAdminRequired
	 * 
	 */
	public function loadAlbums(){
			
			$SQL="SELECT  `AA`.`id`,`AA`.`name`,`AA`.`year`,`AA`.`cover`,`AA`.`bgcolor`,`AG`.`name` AS `genrename` FROM `*PREFIX*audios_albums` `AA`
						LEFT JOIN `*PREFIX*audios_genre` `AG` ON `AA`.`genre_id` = `AG`.`id`
			 			WHERE  `AA`.`user_id` = ?
			 			ORDER BY `AA`.`name` ASC
			 			";
			
		$stmt = \OCP\DB::prepare($SQL);
		$result = $stmt->execute(array($this->userId));
		$aAlbums='';
		while( $row = $result->fetchRow()) {
			$row['artist'] = $this->loadArtistsToAlbum($row['id']);	
			if($row['cover'] === null){
				$bg=$this->genColorCodeFromText(trim($row['name']),90,7);		
				$row['backgroundColor'] =$bg;
				$row['titlecolor'] =$this->generateTextColor($bg);
				$row['cover'] = '';
			}else{
				
				$row['titlecolor']='';	
				$bgColor = json_decode($row['bgcolor']);
				$row['backgroundColor']='rgba('.$bgColor->{'red'}.','.$bgColor->{'green'}.','.$bgColor->{'blue'}.',0.7)';
				$row['titlecolor'] =$this->generateTextColor($bgColor,true);
				$row['cover'] = 'data:image/jpg;base64,'.$row['cover'];	
				
			}
			$aAlbums[$row['id']] = $row;
		}
		if(is_array($aAlbums)){
			$aAlbums=self::sortAlbums($aAlbums,'artist');	
			return $aAlbums;
		}else{
			return false;
		}
	}
	
	private function loadArtistsToAlbum($iAlbumId){
    	$stmtCount = \OCP\DB::prepare( 'SELECT `artist_id`, COUNT(`artist_id`) AS `ARTISTSCOUNT`  FROM `*PREFIX*audios_album_artists` WHERE  `album_id` = ?' );
		$resultCount = $stmtCount->execute(array($iAlbumId));
		$rowCount = $resultCount->fetchRow();
	//	\OCP\Util::writeLog('audios','ROWCOUNT:'.$rowCount['ARTISTSCOUNT'],\OCP\Util::DEBUG);
		if((int)$rowCount['ARTISTSCOUNT'] === 1){
			$stmt = \OCP\DB::prepare( 'SELECT `name`  FROM `*PREFIX*audios_artists` WHERE  `id` = ?' );
			$result = $stmt->execute(array($rowCount['artist_id']));
			$row = $result->fetchRow();
			
			return $row['name'];
		}else{
			return (string) $this->l10n->t('Various Artists');
		}
    }
	
	public function loadSongs(){
		$SQL="SELECT  `AT`.`id`,`AT`.`title`,`AT`.`number`,`AT`.`album_id`,`AT`.`artist_id`,`AT`.`length`,`AT`.`file_id`,`AT`.`bitrate`,`AT`.`mimetype`,`AA`.`name` AS `artistname` FROM `*PREFIX*audios_tracks` `AT`
						LEFT JOIN `*PREFIX*audios_artists` `AA` ON `AT`.`artist_id` = `AA`.`id`
			 			WHERE  `AT`.`user_id` = ?
			 			ORDER BY `AT`.`album_id` ASC,`AT`.`number` ASC
			 			";
			
		$stmt = \OCP\DB::prepare($SQL);
		$result = $stmt->execute(array($this->userId));
		$aSongs='';
		while( $row = $result->fetchRow()) {
				
			$path = \OC\Files\Filesystem::getPath($row['file_id']);
			
			if(\OC\Files\Filesystem::file_exists($path)){
				$row['link'] = \OCP\Util::linkToRoute('audios.music.getAudioStream',array('file'=>$path));
				$aSongs[$row['album_id']][] = $row;
			}else{
				$this->deleteFromDB($row['id'],$row['album_id'],$row['artist_id'],$row['file_id']);
			}	
			
		}
		if(is_array($aSongs)){
			return $aSongs;
		}else{
			return false;
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public  function searchProperties($searchquery){
		
	 
		$SQL="SELECT  `id`,`name` FROM `*PREFIX*audios_albums` WHERE   (`name` LIKE ? OR `year` LIKE ?) AND `user_id` = ?";
				 
		 $stmt = \OCP\DB::prepare($SQL);
		
		$result = $stmt->execute(array('%'.addslashes($searchquery).'%', '%'.addslashes($searchquery).'%', $this->userId));
		$aAlbum ='';
		if(!is_null($result)) {
			while( $row = $result->fetchRow()) {
			
				$aAlbum[] = [
					'id' => $row['id'],
					'name' => $row['name'],
				];
			}
		}
		
		$SQL="SELECT  `AT`.`title`,`AA`.`name`,`AA`.`id`,`AR`.`name` AS artistname FROM `*PREFIX*audios_tracks` `AT` 
					LEFT JOIN `*PREFIX*audios_albums` `AA` ON `AT`.`album_id` = `AA`.`id`
					LEFT JOIN `*PREFIX*audios_artists` `AR` ON `AT`.`artist_id` = `AR`.`id`
					WHERE   (`AT`.`title` LIKE ?  OR `AR`.`name` LIKE ? ) AND `AT`.`user_id` = ?";
				 
		 $stmt = \OCP\DB::prepare($SQL);
		
		$result = $stmt->execute(array('%'.addslashes($searchquery).'%', '%'.addslashes($searchquery).'%', $this->userId));
		$aTrack ='';
		if(!is_null($result)) {
			while( $row = $result->fetchRow()) {
			
				$aTrack[] = [
					'id' => $row['id'],
					'name' => $row['title'].' - '.$row['artistname'].'  ('.$row['name'].')',
				];
			}
		}
		
		if(is_array($aAlbum) && is_array($aTrack)){
			$result=array_merge($aAlbum,$aTrack);
			return $result;
		}
		if(is_array($aAlbum) && !is_array($aTrack)){
			return $aAlbum;
		}
		if(is_array($aTrack) && !is_array($aAlbum)){
				//\OCP\Util::writeLog('audios','COUNTARRAYALBUM:'.count($aAlbum),\OCP\Util::DEBUG);		
			return $aTrack;
		}
		if(!is_array($aTrack) && !is_array($aAlbum)){
			return array();
		}
	}
	
	private function deleteFromDB($Id,$iAlbumId,$iArtistId,$fileId){
		
		//DELETE FROM MATRIX album_artists
		/*
		$stmtCount = \OCP\DB::prepare( 'SELECT COUNT(`artist_id`) AS `ARTISTSCOUNT`  FROM `*PREFIX*audios_album_artists` WHERE `album_id` = ?' );
		$resultCount = $stmtCount->execute(array($iAlbumId));
		$row = $resultCount->fetchRow();
		if((int)$row['ARTISTSCOUNT'] === 1){
			$stmt1 = \OCP\DB::prepare( 'DELETE FROM `*PREFIX*audios_album_artists` WHERE `artist_id` = ? AND `album_id` = ?' );
			$stmt1->execute(array($iArtistId, $iAlbumId));
		}*/
		//DELETE album
		$stmtCountAlbum = \OCP\DB::prepare( 'SELECT COUNT(`album_id`) AS `ALBUMCOUNT`  FROM `*PREFIX*audios_tracks` WHERE `album_id` = ?' );
		$resultAlbumCount = $stmtCountAlbum->execute(array($iAlbumId));
		$rowAlbum = $resultAlbumCount->fetchRow();
		if((int)$rowAlbum['ALBUMCOUNT'] === 1){
			$stmt2 = \OCP\DB::prepare( 'DELETE FROM `*PREFIX*audios_albums` WHERE `id` = ? AND `user_id` = ?' );
			$stmt2->execute(array($iAlbumId, $this->userId));
		}
		
		$stmt = \OCP\DB::prepare( 'DELETE FROM `*PREFIX*audios_tracks` WHERE `user_id` = ? AND `id` = ?' );
		$stmt->execute(array($this->userId, $Id));
		
	}
	
	 public static function sortAlbums($files, $sortAttribute = 'name', $sortDescending = false) {
		$sortFunc = 'compareAlbumNames';
		self::$sortType=$sortAttribute;
		usort($files, array('\OCA\Audios\Controller\MusicController', $sortFunc));
		if ($sortDescending) {
			$files = array_reverse($files);
		}
		return $files;
	}
	
   public static function compareAlbumNames($a, $b) {
			return \OCP\Util::naturalSortCompare($a[self::$sortType], $b[self::$sortType]);
	}
	
		/*
	 * @brief generates the text color for the calendar
	 * @param string $calendarcolor rgb calendar color code in hex format (with or without the leading #)
	 * (this function doesn't pay attention on the alpha value of rgba color codes)
	 * @return boolean
	 */
	private function generateTextColor($calendarcolor,$isRgb=false) {
		if($isRgb === false){	
			if(substr_count($calendarcolor, '#') == 1) {
				$calendarcolor = substr($calendarcolor,1);
			}
			$red = hexdec(substr($calendarcolor,0,2));
			$green = hexdec(substr($calendarcolor,2,2));
			$blue = hexdec(substr($calendarcolor,4,2));
			//recommendation by W3C
			$computation = ((($red * 299) + ($green * 587) + ($blue * 114)) / 1000);
			return ($computation > 130)?'#000000':'#FAFAFA';
		}else{
			$red = hexdec($calendarcolor->{'red'});
			$green = hexdec($calendarcolor->{'green'});
			$blue = hexdec($calendarcolor->{'blue'});
			/*
			$cRed=255-$red;
			$cGreen=255-$green;
			$cBlue=255-$blue;
			
			return 'rgba('.$cRed.','.$cGreen.','.$cGreen.',0.7)';*/
			$computation = ((($red * 299) + ($green * 587) + ($blue * 114)) / 1000);
			return ($computation > 130)?'#000000':'#FAFAFA';
		}
		
		
		
	}
	
	
	 /**
     * genColorCodeFromText method
     *
     * Outputs a color (#000000) based Text input
     *
     * (https://gist.github.com/mrkmg/1607621/raw/241f0a93e9d25c3dd963eba6d606089acfa63521/genColorCodeFromText.php)
     *
     * @param String $text of text
     * @param Integer $min_brightness: between 0 and 100
     * @param Integer $spec: between 2-10, determines how unique each color will be
     * @return string $output
	  * 
	  */
	  
	 private function genColorCodeFromText($text, $min_brightness = 100, $spec = 10){
        // Check inputs
        if(!is_int($min_brightness)) throw new \Exception("$min_brightness is not an integer");
        if(!is_int($spec)) throw new \Exception("$spec is not an integer");
        if($spec < 2 or $spec > 10) throw new Exception("$spec is out of range");
        if($min_brightness < 0 or $min_brightness > 255) throw new \Exception("$min_brightness is out of range");

        $hash = md5($text);  //Gen hash of text
        $colors = array();
        for($i=0; $i<3; $i++) {
            //convert hash into 3 decimal values between 0 and 255
            $colors[$i] = max(array(round(((hexdec(substr($hash, $spec * $i, $spec))) / hexdec(str_pad('', $spec, 'F'))) * 255), $min_brightness));
        }

        if($min_brightness > 0) {
            while(array_sum($colors) / 3 < $min_brightness) {
                for($i=0; $i<3; $i++) {
                    //increase each color by 10
                    $colors[$i] += 10;
                }
            }
        }

        $output = '';
        for($i=0; $i<3; $i++) {
            //convert each color to hex and append to output
            $output .= str_pad(dechex($colors[$i]), 2, 0, STR_PAD_LEFT);
        }

        return '#'.$output;
    }
	
	

	
}