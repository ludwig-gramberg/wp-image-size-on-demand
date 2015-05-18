<?php
/**
 * Plugin Name: Image on Demand
 * Plugin URI: https://github.com/ludwig-gramberg/wp-image-size-on-demand
 * Description: WP Profiler
 * Version: 0.1
 * Author: Ludwig Gramberg
 * Author URI: http://www.ludwig-gramberg.de/
 * Text Domain:
 * License: MIT
 */

/**
 * @param int $attachement_id
 * @param null $width
 * @param null $height
 * @param string $mode
 *      fit : best fit, max width and max height is applied
 *      exact : only works with width and height, best fit, images is extended, background_color applies
 *      crop : only works with width and height, image is cropped to fill result entirely
 * @param null $background_color
 * @param int $quality
 * @param string $type
 *      png or jpg
 * @param string $ver
 *      optional param to salt image link for cache invalidation
 * @return mixed|string
 */
function get_on_demand_image($attachement_id, $width = null, $height = null, $mode = 'fit', $background_color = null, $quality = .8, $type = 'jpg') {

    $cacheParam = (defined('ON_DEMAND_IMAGE_CACHE_VERSION') ? '?ver='.ON_DEMAND_IMAGE_CACHE_VERSION : '');

    if(!preg_match('/^(fit|exact|crop)$/', $mode)) {
        trigger_error('invalid mode '.$mode, E_USER_WARNING);
        return '';
    }

    $uploadsDir = ABSPATH.'wp-content/uploads';
    $filePath = get_attached_file($attachement_id, true);

    if($width || $height) {

        // get dimension of source image
        $out = array();
        $ret = null;
        exec('identify -format "%wx%h" '.escapeshellarg($filePath), $out, $ret);
        $source_width = null;
        $source_height = null;
        if(preg_match('/^([0-9]+)x([0-9]+)/', $out[0], $m)) {
            $source_width = (int)$m[1];
            $source_height = (int)$m[2];
        }

        syslog(LOG_NOTICE, 'fka_sh:'.$source_height);
        syslog(LOG_NOTICE, 'fka_sw:'.$source_width);

        if(!$width || !$height) {
            $mode = 'fit';
        }

        $resizedDir = $uploadsDir.'/resized';
        if(!is_dir($resizedDir)) {
            mkdir($resizedDir);
        }

        $optionsKey = array(2); // change this if you change the code of the plugin in order to force recreation of images
        $optionsKey[] = $mode;

        if($background_color) {
            $optionsKey[] = $background_color;
        }
        if($type == 'png') {
            $optionsKey[] = $quality;
        }
        $optionsKey = md5(implode('|',$optionsKey));

        $targetFile = preg_replace('/^(.*)\/([^\/]+\.)(png|jpg|jpeg)$/i', '\\2', $filePath);
        $targetFile .= $type == 'png' ? 'png' : 'jpg';
        $targetFile = $attachement_id.'_'.$width.'x'.$height.'-'.$optionsKey.'-'.$targetFile;
        $targetPath = $resizedDir.'/'.$targetFile;

        if(file_exists($targetPath)) {
            return '/wp-content/uploads/resized/'.$targetFile.$cacheParam;
        }

        // create file using imagick

        $command = 'convert';
        $command .= ' '.escapeshellarg($filePath);
        $command .= ' -flatten';
        $command .= ' -strip';

        if($type != 'png' || $background_color !== null) {
            $command .= ' -alpha remove';
        }

        if($type == 'png' || $background_color !== null) {
            $command .= ' -background '.escapeshellarg($type == 'png' && $background_color === null ? 'transparent' : $background_color);
        }

        // width and height

        if($mode == 'exact') {
            $command .= ' -gravity center';
            $command .= ' -resize '.$width.'x'.$height;
            $command .= ' -extent '.$width.'x'.$height;
        }
        if($mode == 'crop') {
            $gravity = 'center';
            if($source_height !== null && $source_height > $source_width) {
                $gravity = 'north';
            }
            $command .= ' -gravity '.$gravity;
            $command .= ' -resize '.escapeshellarg($width.'x'.$height.'^');
            $command .= ' -extent '.$width.'x'.$height;
        }
        if($mode == 'fit') {
            if($height && $width) {
                $command .= ' -resize '.$width.'x'.$height;
            } elseif($width) {
                $command .= ' -resize '.$width.'x';
            } elseif($height) {
                $command .= ' -resize x'.$width;
            }
        }

        if($type != 'png') {
            $command .=' -quality '.number_format($quality*100,0);
        }

        $command .= ' '.escapeshellarg($targetPath);

        $out = array();
        $ret = null;
        exec($command, $out, $ret);

        if($ret > 0) {
            trigger_error('convert returned code '.$ret.': '.implode("\n", $out), E_USER_WARNING);
            return '';
        }

        return '/wp-content/uploads/resized/'.$targetFile.$cacheParam;
    } else {
        return '/'.str_replace(ABSPATH, '', $filePath).$cacheParam;
    }
};

add_action('delete_attachment', 'delete_on_demand_images');
add_action('edit_attachment', 'delete_on_demand_images');

function delete_on_demand_images($id) {
    $uploadsDir = ABSPATH.'wp-content/uploads/resized';
    exec('rm '.$uploadsDir.'/'.$id.'-*');
}