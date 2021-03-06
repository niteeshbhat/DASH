<?php
/* This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

function construct_flags($period, $adaptation_set, $representation){
    global $session_dir, $mpd_features, $dashif_conformance;
    
    ## @minimumBufferTime 
    $timeSeconds = (string) time_parsing($mpd_features['minBufferTime']);
    $processArguments = ' -minbuffertime ' . $timeSeconds;
    
    ## @bandwidth
    $bandwidth = $representation['bandwidth'];
    $processArguments .= ' -bandwidth ' . $bandwidth;
    
    ## @width
    $width = ($adaptation_set['width'] == NULL) ? $representation['width'] : $adaptation_set['width'];
    $width = (!$width) ? 0 : $width;
    $processArguments .= ' -width ' . $width;
    
    ## @height
    $height = ($adaptation_set['height'] == NULL) ? $representation['height'] : $adaptation_set['height'];
    $height = (!$height) ? 0 : $height;
    $processArguments .= ' -height ' . $height;

    ## @sar
    $sar = ($adaptation_set['sar'] == NULL) ? $representation['sar'] : $adaptation_set['sar'];
    if($sar != NULL){
        $sar_x_y = explode(':', $sar);
        $processArguments .= ' -sarx ' . $sar_x_y[0] . ' -sary ' . $sar_x_y[1];
    }
    
    ## dynamic @type
    $dynamic = ($mpd_features['type'] == 'dynamic') ? ' -dynamic' : '';
    $processArguments .= $dynamic;
    
    ## @startWithSAP
    $startWithSAP = ($adaptation_set['startWithSAP'] == NULL) ? $representation['startWithSAP'] : $adaptation_set['startWithSAP'];
    if($startWithSAP != NULL)
        $processArguments .= ' -startwithsap ' . $startWithSAP;
    
    ## @profiles
    $ondemand = array('urn:mpeg:dash:profile:isoff-on-demand:2011', 'urn:dvb:dash:profile:dvb-dash:isoff-ext-on-demand:2014');
    $live = array('urn:mpeg:dash:profile:isoff-live:2011', 'urn:dvb:dash:profile:dvb-dash:isoff-ext-live:2014');
    $main = array('urn:mpeg:dash:profile:isoff-main:2011');
    $dash264 = array('http://dashif.org/guidelines/dash264');
    
    $profiles = $mpd_features['profiles'];
    if($adaptation_set['profiles'] != NULL)
        $profiles = $adaptation_set['profiles'];
    if($representation['profiles'] != NULL)
        $profiles = $representation['profiles'];
    
    $profiles = explode(',', $profiles);
    foreach($profiles as $profile){
        if(strpos($profile, ' ') !== FALSE)
            $profile = str_replace(' ', '', $profile);
        
        if(in_array($profile, $ondemand))
            $processArguments .= ' -isoondemand';
        if(in_array($profile, $live))
            $processArguments .= ' -isolive';
        if(in_array($profile, $main))
            $processArguments .= ' -isomain';
        if(strpos($profile, $dash264) !== FALSE || $dashif_conformance)
            $processArguments .= ' -dash264base';
    }
    
    ## ContentProtection
    $content_protection_len = (!$adaptation_set['ContentProtection']) ? sizeof($representation['ContentProtection']) : sizeof($adaptation_set['ContentProtection']);
    if($content_protection_len > 0 && strpos($processArguments, 'dash264base') !== FALSE)
        $processArguments .= ' -dash264enc';
    
    ## @codecs
    $codecs = ($adaptation_set['codecs'] == NULL) ? $representation['codecs'] : $adaptation_set['codecs'];
    if($codecs == NULL)
        $codecs = 0;
    $processArguments .= ' -codecs ' . $codecs;
    
    ## @indexRange
    $indexRange = find_attribute(array($period, $adaptation_set, $representation), 'indexRange');
    if($indexRange != NULL)
        $processArguments .= ' -indexrange ' . $indexRange;
    
    ## AudoChannelConfiguration
    $audioChannelConfiguration = (empty($adaptation_set['AudioChannelConfiguration'])) ? $representation['AudioChannelConfiguration'][0]['value'] : $adaptation_set['AudioChannelConfiguration'][0]['value'];
    $audioChannelConfiguration = ($audioChannelConfiguration == NULL) ? 0 : $audioChannelConfiguration;
    $processArguments .= ' -audiochvalue ' . $audioChannelConfiguration;
    
    ## RepresentationIndex
    $representationIndex = find_attribute(array($period, $adaptation_set, $representation), 'RepresentationIndex');
    if($representationIndex != NULL)
        $processArguments = $processArguments . " -repIndex";
    
    ## ContentProtection
    $content_protections = $adaptation_set['ContentProtection'];
    if(!empty($content_protections)){
        foreach($content_protections as $content_protection){
            if($content_protection['cenc:default_KID'])
                $default_KID = $content_protection['cenc:default_KID'];
            
            $cencNS="urn:mpeg:cenc:2013:pssh";
            $cenc_pssh=$content_protection[$cencNS];
            if(!empty($cenc_pssh))
                $psshBox[]=$cenc_pssh;
            
            $ContentProtect_arr = array('default_KID' => $default_KID, 'psshBox' => $psshBox);
        }
        
        if($default_KID !== null){
            $processArguments = $processArguments . " -default_kid ";
            $processArguments = $processArguments . $default_KID;
        }
        
        $psshCount=sizeof($psshBox);
        if($psshCount>0){
            $processArguments = $processArguments . " -pssh_count ";
            $processArguments = $processArguments . $psshCount;                   
            for($i=0; $i< $psshCount ; $i++){
                $psshBox= $psshBox[$i];
                $processArguments = $processArguments . " -psshbox ";
                $pssh_file_loc=$session_dir."/psshBox".$i.".txt";
                $pssh_file=fopen($pssh_file_loc, "w");
                fwrite($pssh_file, $psshBox);
                fclose($pssh_file);
                $processArguments = $processArguments . $pssh_file_loc;
            }
        }
    }
    
    return $processArguments;
}

function find_attribute($elements, $attribute){
    $return_val = NULL;
    foreach($elements as $element){
        if($element['SegmentBase'] != NULL)
            $temp_attribute = $element['SegmentBase'][0][$attribute];
        elseif($element['SegmentTemplate'] != NULL){
            $segment_template = $element['SegmentTemplate'][0];
            $segment_base = $segment_template['SegmentBase'][0];
            if($segment_base != NULL)
                $temp_attribute = $segment_template['SegmentBase'][0][$attribute];
        }
        elseif($element['SegmentList'] != NULL){
            $segment_list = $element['SegmentList'][0];
            $segment_base = $segment_list['SegmentBase'][0];
            if($segment_base != NULL)
                $temp_attribute = $segment_template['SegmentBase'][0][$attribute];
        }
        
        if($temp_attribute != NULL)
            $return_val = $temp_attribute; 
    }
    
    return $return_val;
}