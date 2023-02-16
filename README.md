# MovieSubBaker 🍿📽️🎬

A small CLI tool (built on PHP, and simple BASH scripts) to search for any new video files added to your library, retrieve matching subtitles from using Filebot (OpenSubtitles) and prebake the subs into the video file as a Matroska Video File Video (MKV) container which can then be ready by older smart TV's.

Tired of your old smart TV which can't fetch subtitles for videos automatically, say hello to MovieSubBaker. It does the following

1. Scans the target folder (provided in the script path) for any new video files (.avi or .mp4, you can add more formats)
2. Checks if a matching (same file name with .srt extension) subtitle is already available in the same folder as the video file
3. If no subtitles are found, it triggers Filebot to fetch matching subtitles in lang-en (you can change this) for each new movie from OpenSubtitles
4. Finally it wraps both the Subtitle and the Video file into a single MKV container
5. If MKVMerge doesn't support that codec, the script falls back to FFmpeg to transpile the new container
6. Finally only if the baking was successfully completed, it deletes the original video and subtitle files for housekeeping


Dependancies
============
1. Filebot
2. MKVMerge
3. FFMpeg (fallback in case MKVMerge doesn't work)
