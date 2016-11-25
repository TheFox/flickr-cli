<?php

namespace TheFox\FlickrCli;

class FlickrCli{
	
	const NAME = 'FlickrCli';
	const VERSION = '0.2.0';
	
	const UPLOAD_PROGRESSBAR_ITEMS = 35;
	const DOWNLOAD_PROGRESSBAR_ITEMS = 35;
	const DOWNLOAD_STREAM_READ_LEN = 4096;
	const CLEAR_CHAR = ' ';
	const FILES_INORE = array('.', '..', '.DS_Store');
	const ACCEPTED_EXTENTIONS = array('jpg', 'jpeg', 'png', 'gif', 'mov', 'avi', 'mts', 'mp4');
	
}
