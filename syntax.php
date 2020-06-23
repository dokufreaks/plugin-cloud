<?php

/**
 * Cloud Plugin: shows a cloud of the most frequently used words
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */
class syntax_plugin_cloud extends DokuWiki_Syntax_Plugin
{
    protected $knownFlags = array('showCount');
    protected $stopwords = null;

    public function getType() { return 'substition'; }
    public function getPType() { return 'block'; }
    public function getSort() { return 98; }

    /**
     * Connect pattern to lexer
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('~~\w*?CLOUD.*?~~', $mode, 'plugin_cloud');
    }

    /**
     * Handle the match
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $match = substr($match, 2, -2); // strip markup

        if (substr($match, 0, 3) == 'TAG') {
            $type = 'tag';
        } elseif (substr($match, 0, 6) == 'SEARCH') {
            $type = 'search';
        } else {
            $type = 'word';
        }

        list($num, $ns) = explode('>', $match, 2);
        list($junk, $num) = explode(':', $num, 2);
        $flags = null;
        if (preg_match ('/\[.*\]/', $junk, $flags) === 1) {
            $flags = trim ($flags[0], '[]');
            $found = explode(',', $flags);
            $flags = array();
            foreach ($found as $flag) {
                if (in_array($flag, $this->knownFlags)) {
                    // Actually we just set flags as present
                    // Later we might add values to flags like key=value pairs
                    $flags[$flag] = true;
                }
            }
        }

        if (!is_numeric($num)) $num = 50;
        $namespaces = is_null($ns) ? null : explode('|', $ns);

        return array($type, $num, $namespaces, $flags);
    }

    /**
     * Create output
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        global $conf;

        list($type, $num, $namespaces, $flags) = $data;
        if ($format == 'xhtml') {

            if ($type == 'tag') { // we need the tag helper plugin
                /** @var helper_plugin_tag $tag */
                if (plugin_isdisabled('tag') || (!$tag = plugin_load('helper', 'tag'))) {
                    msg('The Tag Plugin must be installed to display tag clouds.', -1);
                    return false;
                }
                $cloud = $this->getTagCloud($num, $min, $max, $namespaces, $tag);
            } elseif($type == 'search') {
                /** @var helper_plugin_searchstats $helper */
                $helper = plugin_load('helper', 'searchstats');
                if ($helper) {
                    $cloud = $helper->getSearchWordArray($num);
                    $this->filterCloud($cloud, 'search_blacklist');
                    // calculate min/max values
                    $min = PHP_INT_MAX;
                    $max = 0;
                    foreach ($cloud as $size) {
                        $min = min($size, $min);
                        $max = max($size, $max);
                    }
                } else {
                    msg('You have to install the searchstats plugin to use this feature.', -1);
                    return false;
                }
            } else {
                $cloud = $this->getWordCloud($num, $min, $max);
            }
            if (!is_array($cloud) || empty($cloud)) return false;
            $delta = ($max - $min) / 16;

            // prevent caching to ensure the included pages are always fresh
            $renderer->info['cache'] = false;

            // and render the cloud
            $renderer->doc .= '<div class="cloud">'.DOKU_LF;
            foreach ($cloud as $word => $size) {
                if ($size < $min + round($delta)) $class = 'cloud1';
                elseif ($size < $min + round(2*$delta)) $class = 'cloud2';
                elseif ($size < $min + round(4*$delta)) $class = 'cloud3';
                elseif ($size < $min + round(8*$delta)) $class = 'cloud4';
                else $class = 'cloud5';

                $name = $word;
                if ($type == 'tag' && isset($tag)) {
                    $id = $word;
                    $exists = false;
                    resolve_pageID($tag->namespace, $id, $exists);
                    if ($exists) {
                        $link = wl($id);
                        if ($conf['useheading']) {
                            $name = p_get_first_heading($id, false) ?: $word;
                        }
                    } else {
                        $link = wl($id, array('do'=>'showtag', 'tag'=>$word));
                    }
                    $title = $word;
                    $class .= ($exists ? '_tag1' : '_tag2');
                } else {
                    if ($conf['userewrite'] == 2) {
                        $link = wl($word, array('do'=>'search', 'id'=>$word));
                        $title = $size;
                    } else {
                        $link = wl($word, 'do=search');
                        $title = $size;
                    }
                }

                if ($flags['showCount'] === true) {
                    $name .= '('.$size.')';
                }
                $renderer->doc .= DOKU_TAB . '<a href="' . $link . '" class="' . $class .'"'
                               .' title="' . $title . '">' . hsc($name) . '</a>' . DOKU_LF;
            }
            $renderer->doc .= '</div>' . DOKU_LF;
            return true;
        }
        return false;
    }

    /**
     * Helper function for loading and returning the array with stopwords.
     *
     * Stopwords files are loaded from two locations:
     * - inc/lang/"actual language"/stopwords.txt
     * - conf/stopwords.txt
     *
     * If both files exists, then both files are used - the content is merged.
     */
    protected function getStopwords()
    {
        if ($this->stopwords === null) {
            // load DokuWiki stopwords
            $this->stopwords = idx_get_stopwords();

            // load extra local stopwords
            $swfile = DOKU_CONF.'stopwords.txt';
            if (file_exists($swfile)) {
                $this->stopwords = array_merge(
                        $this->stopwords,
                        file($swfile, FILE_IGNORE_NEW_LINES)
                );
            }
        }
        return $this->stopwords;
    }

    /**
     * Applies filters on the cloud:
     * - removes all short words, see config option 'minimum_word_length'
     * - removes all words in configured blacklist $balcklistName from $cloud array
     */
    protected function filterCloud(&$cloud, $balcklistName)
    {
        // Remove short words
        $min = $this->getConf('minimum_word_length');
        foreach ($cloud as $key => $count) {
            if (iconv_strlen($key) < $min)
                unset($cloud[$key]);
        }

        // Remove stopwords
        foreach ($this->getStopwords() as $word) {
            if (isset($cloud[$word]))
                unset($cloud[$word]);
        }

        // Remove word which are on the blacklist
        $blacklist = $this->getConf($balcklistName);
        if (!empty($blacklist)) {
            $blacklist = explode(',', $blacklist);
            $blacklist = str_replace(' ', '', $blacklist); // remove spaces

            foreach ($blacklist as $word) {
                if (isset($cloud[$word]))
                    unset($cloud[$word]);
            }
        }
    }

    /**
     * Returns the sorted word cloud array
     */
    protected function getWordCloud($num, &$min, &$max)
    {
        global $conf;

        $cloud = array();

        if (@file_exists($conf['indexdir'].'/page.idx')) { // new word-length based index
            $lengths = idx_indexLengths(0);
            foreach ($lengths as $len) {
                $idx      = idx_getIndex('i', $len);
                $word_idx = idx_getIndex('w', $len);
                $this->addWordsToCloud($cloud, $idx, $word_idx);
            }
        } else {                                          // old index
            $idx      = file($conf['cachedir'].'/index.idx');
            $word_idx = file($conf['cachedir'].'/word.idx');
            $this->addWordsToCloud($cloud, $idx, $word_idx);
        }

        $this->filterCloud($cloud, 'word_blacklist');

        return $this->sortCloud($cloud, $num, $min, $max);
    }

    /**
     * Adds all words in given index as $word => $freq to $cloud array
     */
    protected function addWordsToCloud(&$cloud, $idx, $word_idx)
    {
        $wcount = count($word_idx);

        // collect the frequency of the words
        for ($i = 0; $i < $wcount; $i++) {
            $key = trim($word_idx[$i]);
            $value = explode(':', $idx[$i]);
            if (!trim($value[0])) continue;
            $cloud[$key] = count($value);
        }
    }

    /**
     * Returns the sorted tag cloud array
     */
    protected function getTagCloud($num, &$min, &$max, $namespaces = NULL, helper_plugin_tag &$tag)
    {
        $cloud = $tag->tagOccurrences(NULL, $namespaces, true, $this->getConf('list_tags_of_subns'));

        $this->filterCloud($cloud, 'tag_blacklist');

        return $this->sortCloud($cloud, $num, $min, $max);
    }

    /**
     * Sorts and slices the cloud
     */
    protected function sortCloud($cloud, $num, &$min, &$max)
    {
        if (empty($cloud)) return $cloud;

        // sort by frequency, then alphabetically
        arsort($cloud);
        $cloud = array_chunk($cloud, $num, true);
        $max = current($cloud[0]);
        $min = end($cloud[0]);
        ksort($cloud[0]);

        return $cloud[0];
    }
}
//Setup VIM: ex: et ts=4 enc=utf-8 :
