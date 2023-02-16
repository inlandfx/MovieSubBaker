#!/bin/sh

echo "[$(date)] Triggered movie processing wrapper"

# If no lastwatched file exists this means this is the first time the subtitler is being executed, so trigger the 
# script for all movies in last 1 day
# Otherwise we'll use the lastwatched file time to search for files only created after the last watched time
# This will prevent the script from being executed multiple times for the same file even if this wrapper script
# is triggered multiple times :-)

if [ ! -f /volume1/web/moviesubs/lastwatched ]; then

	#Last watched file doesn't exist. Lets create it
	touch /volume1/web/moviesubs/lastwatched
	result="some text here so that the subtitle script is executed"
	
else
	#Find new files
	result="$(find /volume1/root/data/Movies -cnewer /volume1/web/moviesubs/lastwatched \( -name '*.avi' -or -name '*.mp4' \))"
fi
   
   
if [ "$result" != "" ]; then
	echo "[$(date)] Found a match: $result"
	echo "[$(date)] Executing script for immediate processing"
	
	#Get last watched file age
	filemtime=`stat -c %Y /volume1/web/moviesubs/lastwatched`
	currtime=`date +%s`
	
	# "scale=9;(currtime-filemtime)/86400" | /opt/bin/bc | awk '{print ($0-int($0)<0.00000000001)?int($0):int($0)+1}'
	age=$(( (currtime - filemtime) / 86400 + 1))
	
	echo "[$(date)] File seek age defined = $age day(s)"
	php -f /volume1/web/moviesubs/process.php ro path=/volume1/root/data/Movies age=$age findsubs
	
	echo "[$(date)] Processing completed. Resuming watch..."
	
	touch /volume1/web/moviesubs/lastwatched
else
	echo "[$(date)] No new files found."
fi
   
echo "[$(date)] Terminating"