#!/bin/bash
source $ALLSKY_HOME/config.sh
source $ALLSKY_HOME/scripts/filename.sh
source $ALLSKY_HOME/scripts/ftp-settings.sh

cd $ALLSKY_HOME

FPS=${FPS:-25}
ENCODER=${ENCODER:-libx264}

ME="$(basename "$BASH_ARGV0")"	# Include script name in output so it's easier to find in the log file

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

if [ $# -lt 1 -o $# -gt 2 -o "x$1" = "x-h" ] ; then
    TODAY=$(date +%Y%m%d)
    echo -en "${RED}Usage: ${ME} <day> [directory]${NC}\n"
    echo -en "    ex: timelapse.sh ${TODAY}\n"
    echo -en "    or: timelapse.sh ${TODAY} /media/external/allsky/${TODAY}\n"
    exit 3
fi

# Allow timelapses of pictures not in ALLSKY_HOME.
# If $2 is passed, it's the top folder, otherwise use the one in ALLSKY_HOME.
DAY="$1"
if [ "$2" = "" ] ; then
	DIR="$ALLSKY_HOME/images/$DAY"	# Need full pathname for links
else
	DIR="$2"
fi
echo -en "${ME}: ${GREEN}Creating symlinks to generate timelapse${NC}\n"
rm -fr $DIR/sequence	# remove in case it was left over from last run
mkdir -p $DIR/sequence/

# find images, make symlinks sequentially and start avconv to build mp4; upload mp4 and move directory
find "$DIR" -name "*.$EXTENSION" -size 0 -delete

TMP_DIR="$ALLSKY_HOME/tmp"
mkdir -p "$TMP_DIR"
TMP="$TMP_DIR/timelapseTMP.txt"	# capture the "ln" commands in case the user needs to debug
> $TMP
ls -rt $DIR/*.$EXTENSION |
gawk 'BEGIN{ a=1 }
	{
		printf "ln -s %s '$DIR'/sequence/%04d.'$EXTENSION'\n", $0, a;
		printf "ln -s %s '$DIR'/sequence/%04d.'$EXTENSION'\n", $0, a >> "'$TMP'";
		# if (a % 100 == 0) printf "echo '$ME': %d links created so far\n", a;
		a++;
	}' |
bash

# Make sure there's at least one link.
NUM_FILES=$(wc -l < ${TMP})
if [ $NUM_FILES -eq 0 ]; then
	echo -en "*** ${ME}: ${RED}ERROR: No links found!${NC}\n"
	rmdir "${DIR}/sequence"
	exit 1
else
	echo -e "$ME: Created $NUM_FILES links total\n"
fi

SCALE=""
TIMELAPSEWIDTH=${TIMELAPSEWIDTH:-0}

if [ "${TIMELAPSEWIDTH}" != 0 ]; then
    SCALE="-filter:v scale=${TIMELAPSEWIDTH:0}:${TIMELAPSEHEIGHT:0}"
    echo "$ME: Using video scale ${TIMELAPSEWIDTH} * ${TIMELAPSEHEIGHT}"
fi

# "-loglevel warning" gets rid of the dozens of lines of garbage output
# but doesn't get rid of "deprecated pixel format" message when -pix_ftm is "yuv420p".
ffmpeg -y -f image2 \
	-loglevel warning \
	-r $FPS \
	-i $DIR/sequence/%04d.$EXTENSION \
	-vcodec libx264 \
	-b:v ${TIMELAPSE_BITRATE:-2000k} \
	-pix_fmt yuv420p \
	-movflags +faststart \
	$SCALE \
	$DIR/allsky-$DAY.mp4
RET=$?
if [ $RET -ne 0 ]; then
	echo -e "\n${RED}*** $ME: ERROR: ffmpeg failed with RET=$RET"
	echo "Links in '$DIR/sequence' left for debugging."
	echo -e "Remove them when the problem is fixed.${NC}\n"
	exit 1
fi

if [ "$UPLOAD_VIDEO" = true ] ; then
        if [[ "$PROTOCOL" == "S3" ]] ; then
                $AWS_CLI_DIR/aws s3 cp $DIR/allsky-$DAY.mp4 s3://$S3_BUCKET$MP4DIR --acl $S3_ACL &
	elif [[ $PROTOCOL == "local" ]] ; then
                cp $DIR/allsky-$DAY.mp4 $MP4DIR &
        else
		# This sometimes fails with "mv: Access failed: 550 The process cannot access the file because it is being used by another process.  (cp)".
		# xxxx Could it be because the web server is trying to access the file at the same time?
		# If so, should we wait a few seconds and try lftp again?
		# This probably isn't an issue with .jpg files since they are much smaller and the window for
		# simultaneous access is much smaller.

		# "put" to a temp name, then move the temp name to the final name.
		# This is because slow uplinks can cause multiple lftp requests to be running at once,
		# and only one lftp can upload the file at once, otherwise get this error:
		# put: Access failed: 550 The process cannot access the file because it is being used by another process.  (image.jpg)
		# Slow uploads also cause a problem with web pages that try to read the file as it's being uploaded.
		# "tl" = "time lapse" - use a unique temporary name
		TEMP_NAME="tl-$RANDOM"
		(
			FILE="allsky-$DAY.mp4"
			FULL_FILE="/$DIR/$FILE"
			lftp "$PROTOCOL://$USER:$PASSWORD@$HOST:$MP4DIR" -e "set net:max-retries 1; put "$FULL_FILE" -o $TEMP_NAME; rm -f "$FILE"; mv $TEMP_NAME "$FILE"; bye"
			RET=$?
			if [ $RET -ne 0 ]; then
				echo "${RED}*** $ME: ERROR: lftp failed with RET=$RET on '$FULL_FILE'${NC}"
		       fi
		) &
        fi
fi

echo -en "${ME}: ${GREEN}Deleting sequence${NC}\n"
rm -rf $DIR/sequence

echo -en "${ME}: ${GREEN}Timelapse was created${NC}\n"
