<?php 

	//initiate logging
	date_default_timezone_set('Asia/Calcutta');
	
	$log = fopen("/volume1/web/moviesubs/log.txt","a"); //Open log file for writing
	
	fwrite($log, date("[Y-M-d g:iA]"). " ==================================\n");
	fwrite($log, date("[Y-M-d g:iA]"). " Starting movie subtitling process\n");
	fwrite($log, date("[Y-M-d g:iA]"). " ==================================\n");
	
	if(isset($argv)) parse_str(implode('&', array_slice($argv, 1)), $params);
	
	if(!isset($params['path'])){
		fwrite($log, date("[Y-M-d g:iA]"). " No valid path provided. Use argument: <scriptname> path=\"path/to/target/folder\"\n");
		die();
	}
	
	fwrite($log, date("[Y-M-d g:iA]"). " Looking for non-mkv movie files in ".escapeshellarg($params['path']).(isset($params['age']) ? " newer than ".escapeshellarg($params['age'])." days":" with no age criteria")."\n");
	
	$findcmd = 'find '.escapeshellarg($params['path']).((isset($params['age']) && is_numeric($params['age'])) ? " -mtime -".escapeshellarg($params['age']):"").((isset($params['maxdepth']) && is_numeric($params['maxdepth'])) ? " -maxdepth ".escapeshellarg($params['maxdepth']):"").' -type f \( -name "*.avi" -or -name "*.mp4" \) -size +100000000c -size -3000000000c -printf "%p\n" | sort -k 1';
	
	fwrite($log, date("[Y-M-d g:iA]"). " ".$findcmd."\n"); //Enable during debugging only
	
	$filedump = shell_exec($findcmd);
	
	//fwrite($log, date("[Y-M-d g:iA]"). " filedump: ".$filedump."\n"); //Enable during debugging only
	
	$totalprocessed = 0;
	$totalsize = 0;

	$filelist = explode("\n",$filedump);
	
	fwrite($log, date("[Y-M-d g:iA]"). " Found ".(count($filelist)-1)." processable movie file(s) in the target directory\n");
	
	if(count($filelist) > 0){ //which means some valid movie files were found, lets proceed
		
		foreach($filelist as $file){
			
			if($file != "" && !file_exists(pathinfo($file, PATHINFO_DIRNAME)."/".pathinfo($file, PATHINFO_FILENAME).".mkv")){
				fwrite($log, date("[Y-M-d g:iA]"). " Processing file (".($totalprocessed+1)."/".(count($filelist)-1)."): ".escapeshellarg(pathinfo($file, PATHINFO_BASENAME))."\n");
				
				//Now hopefully the matching subtitles already exist in the movie's root folder
				$searchstring = 'find '.escapeshellarg(pathinfo($file, PATHINFO_DIRNAME)).' -type f -name '.str_replace('[','\[',str_replace(']','\]',escapeshellarg(pathinfo($file, PATHINFO_FILENAME)))."*.srt").' -size +1c -printf "%p\n"';
				
				// No idea why I had add a str_replace in this previous this line. Box brackets don't need to be escaped right ?? If it did escapeshellarg() would have already taken care of it :-) But it seems 'find' requires box brackets to be escaped only the -name argument. Spooky!!
				
				fwrite($log, date("[Y-M-d g:iA]"). " Scanning for any pre-existing subtitles using query '".$searchstring."'\n");
				
				$sublist = shell_exec($searchstring);
				$subs = explode("\n",$sublist);
				
				if($sublist == "" && isset($params['findsubs'])){ //0 subs were found, which means we need to do some more work
					
					//Let's give filebot a shot at fetching subtitles for this orphan movie					
					$start = time(); //record the time of starting mkvmerge
					$timeout = 60;
					
					fwrite($log, date("[Y-M-d g:iA]"). " No pre-existing subs found. Executing filebot to fetch subtitles from OpenSubtitles. Timeout is at ".$timeout." seconds\n");
					//$filebot = shell_exec('filebot -get-subtitles --lang en "'.$file.'"');
				
					$subproc = proc_open('exec /usr/local/bin/filebot -get-subtitles --lang en "'.$file.'"', array(array('pipe','w')),$pipe);
					//stream_set_timeout($pipe[0],0);
					//stream_set_blocking($pipe[0],TRUE);
					
					$procstatus = proc_get_status($subproc);

					while (file_exists("/proc/".$procstatus['pid']."/status") && !strpos(file_get_contents("/proc/".$procstatus['pid']."/status"),"zombie"))
					{						
						if(time()-$start <= $timeout)
							sleep(1);
						else
							break; //Filebot has timed out, exit processing
					}
					
					$subproc_result = proc_close($subproc);
					
					if(time()-$start > $timeout) fwrite($log, date("[Y-M-d g:iA]"). " We seem to have timed out and filebot still hadn't completed.\n");
					
					fwrite($log, date("[Y-M-d g:iA]"). " Filebot execution took ".(time()-$start)." seconds\n");
					
					//Now redo the scan for subtitles
					$sublist = shell_exec('find "'.pathinfo($file, PATHINFO_DIRNAME).'" -type f -name "'.pathinfo($file, PATHINFO_FILENAME).'*.srt" -size +1c -printf "%p\n"');
					$subs = explode("\n",$sublist);
					
				} 
				
				if($subs[0] == "" && $sublist == ""){
					
					fwrite($log, date("[Y-M-d g:iA]"). " Couldn't find subtitles for this movie. Moving on to the next item\n");
					continue 1; //No subtitles found, so move to the next item
					
				} else if($subs[0] == "" && $sublist != ""){
					
					$subs[0] = $sublist; //In case only a single subtitle was found there would be no '\n' newline character, so just add that single subtitle
				}
				
				fwrite($log, date("[Y-M-d g:iA]"). " Found ".(count($subs)-1)." matching subtitle file(s) in the movie folder \n");
				
				//If we've reached this point it means that either the current movie file already had a subtitle or filebot just did the trick for us :-)
				//Lets get ready to call ffmpeg and remux the original movie file with a subtitle into a working matroska container 
				//which works on the damn Sony TV. Phew! I'm glad I got that off my chest
				
				$totalprocessed += 1;
				$outputfile = pathinfo($file, PATHINFO_DIRNAME)."/".pathinfo($file, PATHINFO_FILENAME).".tmp.mkv"; //temporary output file
				
				//First lets check if the video format is H.265 format which is unsupported by our dated version of mkvmerge :-( 
				$fileinfo = shell_exec("exec /opt/bin/mkvmerge -i \"".$file."\"");
				$filesize = shell_exec("stat -c \"%s\" \"".$file."\"");
				
				if(strpos($fileinfo, "hvc1") !== FALSE || strpos($fileinfo, "hevc") !== FALSE){ // Which means we have a h265 file on our hands. Exciting! Let's fall back to to ffmpeg, ye old versatile faithfull
				
					//////////////////////////////////////////////////////////
					//							FFMPEG						//
					//////////////////////////////////////////////////////////
					
					fwrite($log, date("[Y-M-d g:iA]"). " Detected HEVC/H.265 format. Switching to our backup slow muxer - ffmpeg\n");
					
					$muxer = "exec /bin/ffmpeg -y -fflags +genpts -i \"".$file."\"";
					
					foreach ($subs as $sub){
						if($sub != "") $muxer .= " -i \"".$sub."\"";
					}
						
					//Add the output parameters		

					$muxer .= " -codec copy \"".$outputfile."\"";
					
					//$remux = shell_exec($muxer);
					
					// set a timeout
					$timeout = max(60,($filesize/pow(1024,2)*1.7)); //min timeout of 60 sec and then 1.7 sec per 1 mb of the file being processed
					
				} else {
					//It's a non-HEVC file. Lets get going with our default muxer - mkvmerge	
					
					//////////////////////////////////////////////////////////
					//							MKVMERGE					//
					//////////////////////////////////////////////////////////
					
					fwrite($log, date("[Y-M-d g:iA]"). " Default muxer mkvmerge selected\n");
					
					$setenv = shell_exec("LC_ALL=C"); //Set the environment variable for MKVMERGE to work, doesn't work in some cases without it
					$muxer = "exec /opt/bin/mkvmerge -o \"".$outputfile."\" \"".$file."\"";
					
					foreach ($subs as $sub){
						if($sub != "") $muxer .= " \"".$sub."\"";
					}
					
					//D-day. set a timeout
					$timeout = max(30,($filesize/pow(1024,2)/100*30)); //min timeout of 30 sec and then 30 sec per 100 mb of the file being processed
					
				}
				
				fwrite($log, date("[Y-M-d g:iA]"). " We have all the ingredients, now let's remux the (".round($filesize/pow(1024,2),0)." Mb) file and bake the cake. The oven timer is set for ".round($timeout,0)." sec\n");
				//fwrite($log, date("[Y-M-d g:iA]"). " ".$muxer."\n"); //Enable only during debugging
				
				$start = time(); //record the time of starting of the muxing process
					
				//die(shell_exec($muxer));
				
				$proc = proc_open($muxer, array(array('pipe','w')),$pipe);
				//stream_set_timeout($pipe[0],0);
				//stream_set_blocking($pipe[0],TRUE);
				$procstatus = proc_get_status($proc);
				
				if (is_resource($proc)) fwrite($log, date("[Y-M-d g:iA]"). " Successfully created shell process for muxer pid(".$procstatus['pid'].")\n");;
				echo "pid: ".$procstatus['pid']."\n";
				sleep(5);

				while (file_exists("/proc/".$procstatus['pid']."/status") && !strpos(file_get_contents("/proc/".$procstatus['pid']."/status"),"zombie")) //Loop until the process exists or is a zombie
				{						
					if(time()-$start <= $timeout)
						sleep(1);
					else
						break; //Muxing has timed out, exit processing
				}
				
				$proc_result = proc_close($proc);
				
				if(time()-$start > $timeout) fwrite($log, date("[Y-M-d g:iA]"). " We seem to have timed out and the cake isn't ready yet.\n");
				
				if(!strpos("unable",$remux) && file_exists($outputfile) && (shell_exec("stat -c \"%s\" \"".$outputfile."\"")*1) >= (shell_exec("stat -c \"%s\" \"".$file."\"")*0.98)){ //The correct keyword was found in muxing output, and the file size of the output was reasonable (>= 98% of the original) which means the remuxing was successful
				
					fwrite($log, date("[Y-M-d g:iA]"). " The temporary remuxed file was successfully generated (in ".(time()-$start)." seconds): ".$outputfile."\n");
					
					if(isset($params['ro'])){
						
						fwrite($log, date("[Y-M-d g:iA]"). " Remove original (ro) flag was indicated. Deleting original file: ".$file."\n");
						
						if(unlink($file)){
							fwrite($log, date("[Y-M-d g:iA]"). " Delete successful\n");
							
							//Delete the original file from the MediaServer index
							fwrite($log, date("[Y-M-d g:iA]"). " Deleting source file from media server index\n");
							$index = shell_exec("/usr/syno/bin/synoindex -d ".$file);
						}
						else
							fwrite($log, date("[Y-M-d g:iA]"). " Delete failed\n");
					}
					
					$newfile = pathinfo($file, PATHINFO_DIRNAME)."/".pathinfo($file, PATHINFO_FILENAME).".mkv";
					
					if(rename($outputfile, $newfile)) { //remove the .tmp extension
						fwrite($log, date("[Y-M-d g:iA]"). " Renamed ".$outputfile." -to-> ".$newfile."\n");
					
						//Add the newly muxed file in the MediaServer index
						fwrite($log, date("[Y-M-d g:iA]"). " Adding newly created file to media server index\n");
						$index = shell_exec("/usr/syno/bin/synoindex -a ".$newfile);
					}
					else
						fwrite($log, date("[Y-M-d g:iA]"). " Error renaming temporary file\n");

				}
				else{
					fwrite($log, date("[Y-M-d g:iA]"). " I waited for ".(time()-$start)." seconds and the muxed file size is ".round(shell_exec("stat -c \"%s\" \"".$outputfile."\"")/pow(1024,2),1)." Mb which is only ".round((shell_exec("stat -c \"%s\" \"".$outputfile."\"")*1)/shell_exec("stat -c \"%s\" \"".$file."\"")*100,0)."% of the source size. This most likely means an incomplete or invalid mux job. Discarding this muxed tmp file\n");
					
					if(file_exists($outputfile)) unlink($outputfile);
				}
			} else if(file_exists(pathinfo($file, PATHINFO_DIRNAME)."/".pathinfo($file, PATHINFO_FILENAME).".mkv"))
				fwrite($log, date("[Y-M-d g:iA]"). " Skipping file: ".pathinfo($file, PATHINFO_BASENAME)." as output file (".(pathinfo($file, PATHINFO_FILENAME).".mkv").") already exists\n");
		}
	}
	
	fwrite($log, date("[Y-M-d g:iA]"). " A total of ".$totalprocessed." files were processed!\n");
	fwrite($log, date("[Y-M-d g:iA]"). " Ending batch process. Goodbye!\n");
	
	fclose($log);
?>