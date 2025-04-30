<?php namespace Vanderbilt\MarksTestModule;

class MarksTestModule extends \ExternalModules\AbstractExternalModule{
    function redcap_survey_page(){
        ?>
        <script>
            // Perform text-to-speech, and play the audio in the user's browser
            function playAudio(text, iconob) {
                var delim = '|--RC--|';
                var thistext;
                var phrases = new Array;
                var phrases_length = new Array;
                var phrase_char_limit = 300; // Send the text in batches by splitting at a given length
                if (isIE) phrase_char_limit = 30000; // Due to incompatibility of IE with WAV, only do phrases in IE as single chunk
                var p = 0;
                // Make sure text ends with punction in order for it to be parsed correctly
                text = trim(text);
                if (text == "") {
                    stopAudio(iconob, false);
                    iconob.remove();
                    return;
                }
                var lastletter = text.slice(-1);
                if (lastletter != '.' && lastletter != '!' && lastletter != '?') {
                    text += '.';
                }
                // Parse into sentences and loop through them
                var sentences = text.match( /[^\.!\?]+[\.!\?]+/g );
                // Loop through sentences and split at X-character mark
                for (var i=0; i<sentences.length; i++) {
                    // Split sentence if longer than phrase_char_limit characters
                    var thissentence = wordwrap(trim(sentences[i]), phrase_char_limit, delim).split(delim);
                    for (var k=0; k<thissentence.length; k++) {
                        // Add sentence fragment to array of phrases
                        phrases_length[p] = thissentence[k].length;
                        phrases[p++] = thissentence[k];
                    }
                }
                // Try to consolidate any short phrases to produce the smallest number of web requests
                do {
                    var phrases2 = new Array;
                    var loops = phrases.length;
                    var p = 0;
                    var mergedSentences = 0;
                    for (var i=0; i<loops; i++) {
                        var combine_them = false;
                        if (i < loops-1) {
                            var combinedsentence = phrases[i]+" "+phrases[i+1];
                            combine_them = (combinedsentence.length <= phrase_char_limit);
                        }
                        if (combine_them) {
                            phrases2[p++] = combinedsentence;
                            mergedSentences++;
                            i++;
                        } else {
                            phrases2[p++] = phrases[i];
                        }
                    }
                    phrases = phrases2;
                } while (mergedSentences > 0);
                // Loop through all sentences/phrases and convert to URLs to play
                var urls = new Array();
                for (var i=0; i<phrases.length; i++) {
                    thistext = phrases[i];
                    // Get URL to call
                    if (page == 'surveys/index.php') {
                        var url = <?=json_encode($this->getUrl('speak.php', true))?> + '&s='+getParameterByName('s')+'&__passthru='+encodeURIComponent('Surveys/speak.php')+'&q='+encodeURIComponent(thistext);
                    } else {
                        var url = app_path_webroot+'Surveys/speak.php?pid='+pid+'&q='+encodeURIComponent(thistext);
                    }
                    // Add URL to array
                    urls[i] = url;
                }
                // Create id for each audio tag to place them in the audio container div
                var audio_item_id = "tts-item-"+Math.floor(Math.random()*10000000000000000);
                $('body').append('<div style="'+(isIE?'':'display:none;')+'" id="'+audio_item_id+'"></div>'); // Don't hide the div for IE or else it won't play
                // Add the "stop" attribute to the speaker icon
                iconob.attr('stop', audio_item_id);
                // Play audio: Loop through all urls and play their audio
                // Add MP3 URL as new Audio tag in the container
                var num_urls = urls.length;
                var sub_item_num_array = new Array();
                var url, sub_item_num, audio_sub_item_id, subitem_id, id_parts, next_id, audioElement;
                for (var i=0; i<num_urls; i++) {
                    // Add loop num to array (to deal with setTimeout issues)
                    sub_item_num_array[i] = i;
                    // Put slight lag on the audio tags so that it doesn't appear as a bunch of simultaneous requests to the server and overwhelm it
                    setTimeout(async function(){
                        // Get the URL
                        url = urls.shift();
                        // Get subitem id
                        sub_item_num = sub_item_num_array.shift();
                        audio_sub_item_id = audio_item_id+'-'+sub_item_num;
                        // Add audio tag to page (use regular JS since jQuery strangely causes a double HTTP request when adding audio element to DOM)
                        audioElement = document.createElement("audio");
                        audioElement.setAttribute("controls", "controls");
                        audioElement.setAttribute("id", audio_sub_item_id);
                        document.getElementById(audio_item_id).appendChild(audioElement);
                        sourceElement = document.createElement("source");

                        /**
                         * On VUMC servers the apache configuration strips "Content-Length" headers
                         * that are added via PHP.  This makes it impossible to implement requests
                         * serving audio files in a way that works on mobile browsers.
                         * 
                         * We work around this by using a FileReader to generate a "src='data:audio/mp3;base64,...'"
                         * string containing the audio data instead.
                         * 
                         * We could modify our apache configuration to solve this, but this workaround
                         * will automatically avoid the issue for any REDCap web server worldwide,
                         * regarldess of configuration.
                         */
                        const response = await fetch(url);
                        const blob = await response.blob();
                        const reader = new FileReader();
                        await new Promise((resolve, reject) => {
                            reader.onload = resolve;
                            reader.onerror = reject;
                            reader.readAsDataURL(blob);
                        });

                        sourceElement.setAttribute("src", reader.result);
                        sourceElement.setAttribute("type", "audio/mp3");
                        document.getElementById(audio_sub_item_id).appendChild(sourceElement);
                        // Bind event for when audio gets to end
                        $('#'+audio_sub_item_id).bind('ended', function() {
                            subitem_id = $(this).attr('id');
                            id_parts = subitem_id.split('-');
                            next_id = audio_item_id+'-'+((id_parts[3]*1)+1);
                            $(this).remove();
                            if ($('#'+next_id).length) {
                                // Another audio tag exists, so play it
                                $('#'+next_id).trigger('play');
                            } else {
                                // No more audio tags, so remove the container to stop
                                $('#'+audio_item_id).remove();
                                // Set the icon img back to original
                                stopAudio(iconob);
                            }
                        });
                        // Play the first item
                        if (sub_item_num == 0) $('#'+audio_sub_item_id).trigger('play');
                    },i*200);
                }
            }
        </script>
        <?php
    }
}