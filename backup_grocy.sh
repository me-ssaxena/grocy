#!/bin/bash

GROCY_PATH="/home/sunny/tools/grocy"
DATA_PATH="$GROCY_PATH/data"
DB_FILE="$DATA_PATH/grocy.db"
PICTURES_DIR="$DATA_PATH/storage/productpictures"

TMP_DIR="/home/sunny/temp"
REMOTE="gdrive:Grocy/rclone"

COUNTER_FILE="$TMP_DIR/.grocy_backup_counter"

# initialize counter if missing
if [ ! -f "$COUNTER_FILE" ]; then
    echo 1 > "$COUNTER_FILE"
fi

COUNT=$(cat "$COUNTER_FILE")
PADDED=$(printf "%02d" "$COUNT")

TMP_WORKDIR="$TMP_DIR/grocy_backup_work"
TMP_ZIP="$TMP_DIR/grocy_backup_$PADDED.zip"
REMOTE_FILE="$REMOTE/grocy_backup_$PADDED.zip"

# prepare temp workspace
rm -rf "$TMP_WORKDIR"
mkdir -p "$TMP_WORKDIR"

# copy database
cp "$DB_FILE" "$TMP_WORKDIR/"

# copy product pictures directory
cp -r "$PICTURES_DIR" "$TMP_WORKDIR/"

# create zip
cd "$TMP_WORKDIR" || exit 1
zip -r "$TMP_ZIP" . >/dev/null

# upload to Google Drive, overwrite slot
rclone copyto "$TMP_ZIP" "$REMOTE_FILE"

# cleanup
rm -rf "$TMP_WORKDIR" "$TMP_ZIP"

# increment counter
COUNT=$((COUNT + 1))
if [ "$COUNT" -gt 30 ]; then
    COUNT=1
fi

echo "$COUNT" > "$COUNTER_FILE"