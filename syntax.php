<?php

use dokuwiki\File\PageResolver;
use dokuwiki\Utf8;

/**
 * Cloud Plugin: shows a cloud of the most frequently used words
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Esther Brunner <wikidesign@gmail.com>
 */
class syntax_plugin_cloud extends DokuWiki_Syntax_Plugin
{
    protected $stopwords = null;

    public function getType()
    {
        return 'substition';
    }

    public function getPType()
    {
        return 'block';
    }

    public function getSort()
    {
        return 98;
    }

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

        list($prefix, $params) = explode('CLOUD', $match, 2);
        if ($prefix === '') {
            $type = 'word';
        } elseif ($prefix === 'TAG') {
            $type = 'tag';
        } elseif ($prefix === 'SEARCH') {
            $type = 'search';
        } else {
            return false;
        }

        list($params, $ns) = explode('>', $params, 2);
        $namespaces = isset($ns) ? array_map('trim', explode('|', $ns)) : [];

        list($options, $num) = explode(':', $params, 2);
        $num = (isset($num) && is_numeric($num)) ? ($num + 0) : 50;

        $flags = [];
        $found = array_map('trim', explode(',', substr($options, 1, -1)));
        foreach ($found as $flag) {
            // Actually we just set flags as present
            // Later we might add values to flags like key=value pairs
            $flags[$flag] = true;
        }

        return [$type, $num, $namespaces, $flags];
    }

    /**
     * Create output
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        global $conf;

        if ($format != 'xhtml') return false;

        list($type, $num, $namespaces, $flags) = $data;
        switch ($type) {
            case 'tag':    // require tag plugin
                $cloud = $this->getTagCloud($num, $min, $max, $namespaces);
                if ($cloud === false) {
                    msg('The Tag Plugin must be installed to display tag clouds.', -1);
                    return false;
                }
                break;
            case 'search': // require searchstats plugin
                $cloud = $this->getSearchCloud($num, $min, $max);
                if ($cloud === false) {
                    msg('You have to install the searchstats plugin to use this feature.', -1);
                    return false;
                }
                break;
            default:
                $cloud = $this->getWordCloud($num, $min, $max);
        }
        if (!is_array($cloud) || empty($cloud)) return false;

        // prevent caching to ensure the included pages are always fresh
        $renderer->nocache();

        // and render the cloud
        $renderer->doc .= '<div class="cloud">' . DOKU_LF;
        $delta = ($max - $min) / 16;
        foreach ($cloud as $word => $size) {
            if ($size < $min + round($delta)) $class = 'cloud1';
            elseif ($size < $min + round(2 * $delta)) $class = 'cloud2';
            elseif ($size < $min + round(4 * $delta)) $class = 'cloud3';
            elseif ($size < $min + round(8 * $delta)) $class = 'cloud4';
            else $class = 'cloud5';

            $name = $word;
            if ($type == 'tag') {
                /** @var helper_plugin_tag $tag */
                isset($tag) || $tag = $this->loadHelper('tag', true);

                $ns = method_exists($tag, 'getNamespace') ? $tag->getNamespace() : $tag->namespace;
                if (class_exists('dokuwiki\File\PageResolver')) {
                    // Compatibility with tag plugin < 2022-09-30
                    $resolver = new PageResolver($ns . ':');
                    $id = $resolver->resolveId($word);
                    $exists = page_exists($id);
                } else {
                    // Compatibility with Hogfather and older
                    $id = $word;
                    $exists = false;
                    resolve_pageID($ns, $id, $exists);
                }

                if ($exists) {
                    $link = wl($id);
                    if ($conf['useheading']) {
                        $name = p_get_first_heading($id, false);
                        if (blank($name)) {
                            $name = $word;
                        }
                    }
                } else {
                    $link = wl($id, ['do' => 'showtag', 'tag' => $word]);
                }
                $title = $word;
                $class .= ($exists ? '_tag1' : '_tag2');
            } else {
                if ($conf['userewrite'] == 2) {
                    $link = wl($word, ['do' => 'search', 'id' => $word]);
                } else {
                    $link = wl($word, 'do=search');
                }
                $title = $size;
            }

            if (array_key_exists('showCount', $flags) && $flags['showCount'] === true) {
                $name .= '(' . $size . ')';
            }
            $renderer->doc .= DOKU_TAB . '<a href="' . $link . '" class="' . $class . '"'
                . ' title="' . $title . '">' . hsc($name) . '</a>' . DOKU_LF;
        }
        $renderer->doc .= '</div>' . DOKU_LF;
        return true;
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
            if (is_callable('dokuwiki\Search\Tokenizer::getInstance')) {
                $this->stopwords = dokuwiki\Search\Tokenizer::getInstance()->getStopwords();
            } else {
                $this->stopwords = idx_get_stopwords();
            }

            // load extra local stopwords
            $swfile = DOKU_CONF . 'stopwords.txt';
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
        if (is_callable('dokuwiki\Utf8\PhpString::strlen')) {
            foreach ($cloud as $key => $count) {
                if (Utf8\PhpString::strlen($key) < $min) {
                    unset($cloud[$key]);
                }
            }
        } else {
            foreach ($cloud as $key => $count) {
                if (utf8_strlen($key) < $min) {
                    unset($cloud[$key]);
                }
            }
        }

        // Remove stopwords
        foreach ($this->getStopwords() as $word) {
            if (isset($cloud[$word])) {
                unset($cloud[$word]);
            }
        }

        // Remove word which are on the blacklist
        $blacklist = $this->getConf($balcklistName);
        if (!empty($blacklist)) {
            $blacklist = array_map('trim', explode(',', $blacklist));
            foreach ($blacklist as $word) {
                if (isset($cloud[$word])) {
                    unset($cloud[$word]);
                }
            }
        }
    }

    /**
     * Returns the sorted word cloud array
     */
    protected function getWordCloud($num, &$min, &$max)
    {
        $cloud = [];

        if (is_callable('dokuwiki\Search\FulltextIndex::getInstance')) {
            $FulltextIndex = dokuwiki\Search\FulltextIndex::getInstance();
            $lengths = $FulltextIndex->getIndexLengths(0);
            $funcGetIndex = [$FulltextIndex, 'getIndex'];
        } else {
            $lengths = idx_indexLengths(0);
            $funcGetIndex = 'idx_getIndex';
        }

        foreach ($lengths as $len) {
            $this->addWordsToCloud($cloud, $funcGetIndex('i', $len), $funcGetIndex('w', $len));
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
    protected function getTagCloud($num, &$min, &$max, $namespaces)
    {
        if (!plugin_isdisabled('tag')) {
            /** @var helper_plugin_tag $tag */
            $tag = $this->loadHelper('tag', true);
            $cloud = $tag->tagOccurrences([], $namespaces, true, $this->getConf('list_tags_of_subns'));
            $this->filterCloud($cloud, 'tag_blacklist');
        } else {
            return false;
        }
        return $this->sortCloud($cloud, $num, $min, $max);
    }

    /**
     * Returns the search cloud array
     *
     * @return array|false
     */
    protected function getSearchCloud($num, &$min, &$max)
    {
        if (!plugin_isdisabled('searchstats')) {
            /** @var helper_plugin_searchstats $helper */
            $helper = $this->loadHelper('searchstats', true);
            $cloud = $helper->getSearchWordArray($num);
            $this->filterCloud($cloud, 'search_blacklist');
        } else {
            return false;
        }

        // calculate min/max values
        $min = PHP_INT_MAX;
        $max = 0;
        foreach ($cloud as $size) {
            $min = min($size, $min);
            $max = max($size, $max);
        }
        return $cloud;
    }

    /**
     * Sorts and slices the cloud
     */
    protected function sortCloud($cloud, $num, &$min, &$max)
    {
        if (empty($cloud)) {
            return $cloud;
        }

        // sort by frequency, then alphabetically
        arsort($cloud);
        $cloud = array_chunk($cloud, $num, true);
        $max = current($cloud[0]);
        $min = end($cloud[0]);
        ksort($cloud[0]);

        return $cloud[0];
    }
}
