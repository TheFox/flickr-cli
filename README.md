# FlickrCLI

A command-line interface to [Flickr](https://www.flickr.com/). Upload and download photos, photo sets, directories via shell.

## Installation

1. Clone from Github:
	
		git clone https://github.com/TheFox/flickr-cli.git

2. Install dependencies:

		composer install --no-dev

3. Go to <https://www.flickr.com/services/apps/create/apply/> to create a new API key.
The first time you run `./application.php auth` you'll be prompted to enter your new consumer key and secret.

## Usage

First, get the access token:

	./application.php auth

### Upload

	./application.php upload [-d DESCRIPTION] [-t TAG,...] [-s SET,...] DIRECTORY...

### Download

	./application.php download -d DIRECTORY [SET...]

To download all photosets to directory `photosets`:

	./application.php download -d photosets

Or to download only the photoset *Holiday 2013*:

	./application.php download -d photosets 'Holiday 2013'

To download all photos into directories named by photo ID
(and so which will not change when you rename albums or photos; perfect for a complete Flickr backup)
you can use the `--id-dirs` option:

	./application.php download -d flickr_backup --id-dirs

This creates a stable directory structure of the form `destination_dir/hash/hash/photo-ID/`
and saves the full original photo file along with a `metadata.yml` file containing all photo metadata.
The hashes, which are the first two sets of two characters of the MD5 hash of the ID,
are required in order to prevent a single directory from containing too many subdirectories
(to avoid problems with some filesystems).

## Flickr API documentation

<http://www.flickr.com/services/api/>

## License

Copyright (C) 2016 Christian Mayer <https://fox21.at>

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details. You should have received a copy of the GNU General Public License along with this program. If not, see <http://www.gnu.org/licenses/>.
