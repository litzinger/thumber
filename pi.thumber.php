<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

/**
 * ExpressionEngine - by EllisLab
 *
 * @package     ExpressionEngine
 * @author      ExpressionEngine Dev Team
 * @copyright   Copyright (c) 2003 - 2011, EllisLab, Inc.
 * @license     http://expressionengine.com/user_guide/license.html
 * @link        http://expressionengine.com
 * @since       Version 2.0
 * @filesource
 */
class Thumber
{
    public $return_data;

    private $base;
    private $thumb_cache_rel_dirname = '/images/thumber';
    private $convert_bin = 'convert';
    private $gs_bin = 'gs';
    private $params = array();

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->base = ee()->TMPL->fetch_param('base', '');
        ee()->load->helper('string');

        if ($this->base == '') {
            $this->base = $_SERVER['DOCUMENT_ROOT'];
        }

        // Set the image cache relative link
        if (ee()->config->item('thumber_cache_dir') !== FALSE) {
            $this->thumb_cache_rel_dirname = ee()->config->item('thumber_cache_dir');
        }

        $this->thumb_cache_dirname = reduce_double_slashes($_SERVER['DOCUMENT_ROOT'] . '/' . $this->thumb_cache_rel_dirname);

        // Override the convert bin?
        if (ee()->config->item('thumber_convert_bin') !== FALSE) {
            $this->convert_bin = ee()->config->item('thumber_convert_bin');
        }

        // Override the gs bin?
        if (ee()->config->item('thumber_gs_bin') !== FALSE) {
            $this->gs_bin = ee()->config->item('thumber_gs_bin');
        }
    }

    private function fetch_params()
    {
        /** -------------------------------------
         * /**  Initialise default parameters
         * /** -------------------------------------*/
        $default_params = array(
            'width' => '84',
            'height' => '108',
            'crop' => 'yes',
            'page' => '1',
            'extension' => 'png',
            'link' => 'no',
            'base' => $_SERVER['DOCUMENT_ROOT']
        );

        $this->params = $default_params;

        $width = ee()->TMPL->fetch_param('width', '');
        $height = ee()->TMPL->fetch_param('height', '');

        if ($width || $height) {
            // if either is specified, override both defaults
            $this->params['width'] = $width;
            $this->params['height'] = $height;
        }


        $this->base = ee()->TMPL->fetch_param('base', '');

        if ($this->base) {
            $this->params['base'] = $this->base;
        }

        /** -------------------------------------
         * /**  Loop through input params, set values
         * /** -------------------------------------*/
        $ignore = array('width', 'height', 'base', 'src');
        if (ee()->TMPL->tagparams) {
            foreach (ee()->TMPL->tagparams as $key => $value) {
                // ignore width and height as special parameters
                if (!array_key_exists($key, $ignore)) {
                    if (array_key_exists($key, $this->params)) {
                        // if it's in the default array, it's used by the plugin
                        $this->params[$key] = $value;
                    } else {
                        // otherwise, it'll just be passed straight to the img tag
                        $this->custom_params[$key] = $value;
                    }
                }
            }
        }

        // this is just for convenience
        $this->params['dimensions'] = $this->params['width'] . 'x' . $this->params['height'];
    }

    /**
     * Check ImageMagick and Ghostscript are installed
     */
    private function lib_check()
    {
        if (exec($this->convert_bin . " -version 2>&1", $output)) {
            if (!preg_match("/(ImageMagick [\d]+[\.][\d]+)/", $output[0])) {
                ee()->TMPL->log_item('**Thumber** Can\'t find ImageMagick on your server.');
                return false;
            }
        }

        if (!is_numeric(exec($this->gs_bin . " --version 2>&1"))) {
            ee()->TMPL->log_item('**Thumber** Can\'t find Ghostscript on your server.');
            return false;
        }

        return true;
    }

    /**
     * Check the cache folder exists and is writable
     */
    private function cache_folder_check()
    {
        if (!file_exists($this->thumb_cache_dirname)) {
            ee()->TMPL->log_item('**Thumber** Cache folder: "' . $this->thumb_cache_rel_dirname . '" does not exist.');
            /* TODO: Try to create cache directory if it doesn't exit */
            /*
            if(mkdir($this->thumb_cache_dirname){
              ee()->TMPL->log_item('**Thumber** Cache folder successfully created at: '. $this->thumb_cache_rel_dirname);
              return true;
            })
            */
            return false;
        }

        if (!is_writable($this->thumb_cache_dirname)) {
            ee()->TMPL->log_item('**Thumber** Cache folder: "' . $this->thumb_cache_rel_dirname . '" is not writable.');
            return false;
        }

        return true;
    }

    /**
     * Get the full path to a file from either an absolute or relative URL
     */
    private function get_fullpath_from_url($src_url)
    {
        if (!$src_url) {
            ee()->TMPL->log_item('**Thumber** No source URL provided.');
            return false;
        }

        // check if the source URL is an absolute URL
        if (substr($src_url, 0, 4) == 'http') {
            $url = parse_url($src_url);

            $src_url = $url['path'];
        }

        ee()->load->helper('string');
        $src_fullpath = reduce_double_slashes($this->base . $src_url);

        if (!file_exists($src_fullpath)) {
            ee()->TMPL->log_item('**Thumber** Source URL: "' . $src_url . '" does not exist.');
            return false;
        }

        return $src_fullpath;
    }

    /**
     * This is where the heavy lifting happens! Call ImageMagick to actually generate the thumbnail
     * according to the specified parameters
     */
    private function generate_conversion($source, $dest)
    {
        $page = intval($this->params["page"]);
        $gs_opts = array(
            "-dSAFER",
            "-dBATCH",
            "-dNOPAUSE",
            "-dNOCACHE",
            "-dNOPLATFONTS",
            "-sDEVICE=png16m",
            "-dTextAlphaBits=4",
            "-dGraphicsAlphaBits=4",
            "-dCompatibilityLevel=1.4",
            "-dColorConversionStrategy=/sRGB",
            "-dProcessColorModel=/DeviceRGB",
            "-dUseCIEColor=true",
            "-r150",
            "-dFirstPage=" . $page,
            "-dLastPage=" . $page,
            "-sOutputFile=" . $dest["fullpath"] . ' ' . $source['fullpath'],
        );

        $modifier = '';
        if ($this->params["width"] && $this->params["height"]) {
            if ($this->params['crop'] == 'yes') {
                $modifier = '^ -gravity center -extent ' . $this->params["dimensions"];
            } else {
                // This modifier forces the specified dimensions,
                // even if it means not preserving aspect ratio
                $modifier = '!';
            }
        }

        $convert_opts = array(
            "-resize " . $this->params["dimensions"] . $modifier,
            $dest['fullpath'],
            $dest['fullpath'],
        );
        $exec_str = $this->gs_bin . " " . implode(' ', $gs_opts) . " && " . $this->convert_bin . " " . implode(' ', $convert_opts) . " 2>&1";
        $error = exec($exec_str);

        // Ghostscript will output "Page x"
        if ($error && !preg_match('/^Page/', $error)) {
            ee()->TMPL->log_item('**Thumber** ' . $error);
            return false;
        }

        return true;
    }

    /**
     * The function to be called from templates in order to generate thumbnails from PDFs
     */
    public function create()
    {
        $source = array();
        $source["url"] = trim(ee()->TMPL->fetch_param('src'));

        // allow path below root with 'path' parameter
        $source["fullpath"] = trim(ee()->TMPL->fetch_param('path'));
        if (!$source["fullpath"]) {
            $source["fullpath"] = $this->get_fullpath_from_url($source["url"]);
        }

        if (!$source["fullpath"]) {
            return;
        }

        if (!$this->lib_check()) {
            return;
        }

        if (!$this->cache_folder_check()) {
            return;
        }

        // populate param and custom_param arrays
        $this->fetch_params();

        $source = array_merge($source, pathinfo($source["fullpath"]));

        // create dest array
        $dest = array();
        $dest["dirname"] = $this->thumb_cache_dirname;

        // create dest filename
        $cropped = ($this->params["width"] && $this->params["height"] && $this->params["crop"] == 'yes') ? '_cropped' : '';
        $param_str = '_pg' . $this->params["page"] . '_' . $this->params["dimensions"] . $cropped;
        $dest["filename"] = $source["filename"] . $param_str;

        // add the rest of the dest array items
        $dest["extension"] = $this->params["extension"];
        $dest["basename"] = $dest["filename"] . "." . $dest["extension"];
        ee()->load->helper('string');
        $dest["fullpath"] = reduce_double_slashes($this->thumb_cache_dirname . '/' . $dest["basename"]);
        $dest["url"] = reduce_double_slashes($this->thumb_cache_rel_dirname . '/' . $dest["basename"]);

        // check whether we have a cached version of the thumbnail
        if ($this->should_generate_thumbnail($source['fullpath'], $dest['fullpath'])) {
            // if it isn't, generate the thumbnail
            $success = $this->generate_conversion($source, $dest);
            if (!$success) {
                return;
            }
        }

        // generate custom param string
        $custom_param_str = '';
        foreach ($this->custom_params as $key => $value) {
            $custom_param_str .= $key . '="' . $value . '" ';
        }

        // Get width height string
        $img_width_height = getimagesize($dest["fullpath"]);

        // generate html snippet
        $html_snippet = '<img src="' . $dest["url"] . '" ' . $img_width_height[3] . ' ' . $custom_param_str . ' />';

        if ($this->params["link"] == "yes") {
            $html_snippet = '<a href="' . $source["url"] . '">' . $html_snippet . '</a>';
        }

        return ee()->TMPL->fetch_param('url_only') == 'yes' ? $dest["url"] : $html_snippet;

    }

    /**
     * determine whether or not a new thumbnail image should be created
     * conditions for a TRUE response are: if the destination thumbnail doesn't already exist OR if the source
     * file is NEWER than the destination thumbnail file
     *
     * @param  string $source_path absolute path on the filesystem of the file used to generate the thumbnail
     * @param  string $dest_path   absolute path on the filesystem for the destination thumbnail to be placed
     * @return bool              true if a thumbnail should be created, false if not.
     */
    protected function should_generate_thumbnail($source_path, $dest_path)
    {
        if (!file_exists($dest_path)) {
            return true;
        }

        if (filemtime($source_path) > filemtime($dest_path)) {
            return true;
        }

        return false;
    }

}
