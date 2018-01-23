#!/bin/bash
# Zip the package

package=plg_authentication_steamid

rm $package.zip
zip -r $package.zip steamid.xml steamid.php README.md index.html en-GB.plg_authentication_steamid.*

packagesize=$(ls -lh $package.zip)
echo "Created: $packagesize"
