# FlickrCLI

A command-line interface to [Flickr](https://www.flickr.com/). Upload and download photos, photo sets, directories via shell.

## Project Outlines

The project outlines as described in my blog post about [Open Source Software Collaboration](https://blog.fox21.at/2019/02/21/open-source-software-collaboration.html).

- The main purpose of this software is to provide a command-line interface for Flickr.
- The features should not go beyond Flickrs features and functions. So the features of this software are limited to those of Flickr.
- This list is open. Feel free to request features.

## Installation

1. Clone from Github:

    ```bash
	git clone https://github.com/TheFox/flickr-cli.git
    ```

2. Install dependencies:

    ```bash
	composer install
    ```

3. Go to <https://www.flickr.com/services/apps/create/apply/> to create a new API key.
The first time you run `./bin/flickr-cli auth` you'll be prompted to enter your new consumer key and secret.

## Usage

First, get the access token:

```bash
./bin/flickr-cli auth
```

### Upload

```bash
./bin/flickr-cli upload [-d DESCRIPTION] [-t TAG,...] [-s SET,...] DIRECTORY...
```

### Download

```bash
./bin/flickr-cli download -d DIRECTORY [SET...]
```

To download all photosets to directory `photosets`:

```bash
./bin/flickr-cli download -d photosets
```

Or to download only the photoset *Holiday 2013*:

```bash
./bin/flickr-cli download -d photosets 'Holiday 2013'
```

To download all photos into directories named by photo ID
(and so which will not change when you rename albums or photos; perfect for a complete Flickr backup)
you can use the `--id-dirs` option:

```bash
./bin/flickr-cli download -d flickr_backup --id-dirs
```

This creates a stable directory structure of the form `destination_dir/hash/hash/photo-ID/`
and saves the full original photo file along with a `metadata.yml` file containing all photo metadata.
The hashes, which are the first two sets of two characters of the MD5 hash of the ID,
are required in order to prevent a single directory from containing too many subdirectories
(to avoid problems with some filesystems).

## Usage of the Docker Image

Docker support will be dropped in the future.

## Documentations

- [Flickr API documentation](http://www.flickr.com/services/api/)
- [Docker documentation](https://docs.docker.com/)

## License

Copyright (C) 2016 Christian Mayer <https://fox21.at>

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details. You should have received a copy of the GNU General Public License along with this program. If not, see <http://www.gnu.org/licenses/>.
