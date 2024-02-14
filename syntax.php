<?php

use dokuwiki\Extension\SyntaxPlugin;

/**
 * PageQuery Plugin: search for and list pages, sorted/grouped by name, date, creator, etc
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Symon Bent <hendrybadao@gmail.com>
 *
 * @phpcs      :disable Squiz.Classes.ValidClassName.NotCamelCaps
 */
class syntax_plugin_pagequery extends SyntaxPlugin
{
    public const MAX_COLS = 12;

    public function getType(): string
    {
        return 'substition';
    }

    public function getPType(): string
    {
        return 'block';
    }

    public function getSort(): int
    {
        return 98;
    }

    public function connectTo($mode): void
    {
        // this regex allows multi-line syntax for easier composition/reading
        $this->Lexer->addSpecialPattern('\{\{pagequery>(?m).*?(?-m)\}\}', $mode, 'plugin_pagequery');
    }

    /**
     * Parses all the pagequery options:
     * Insert the pagequery markup wherever you want your list to appear. E.g:
     *
     *   {{pagequery>}}
     *
     *   {{pagequery>[query];fulltext;sort=key:direction,key2:direction;group;limit=??;cols=?;spelldate;proper}}
     *
     * @link https://www.dokuwiki.org/plugin:pagequery See PageQuery page for full details
     */
    public function handle($match, $state, $pos, Doku_Handler $handler): array
    {
        $opt    = [];
        $match  = substr($match, 12, -2); // strip markup "{{pagequery>...}}"
        $params = explode(';', $match);
        // remove any pre/trailing spaces due to multi-line syntax
        $params = array_map('trim', $params);

        $opt['query'] = $params[0];

        // establish some basic option defaults
        $opt['border']    = 'none';     // show borders around entire list and/or between columns
        $opt['bullet']    = 'none';     // bullet style for list items
        $opt['casesort']  = false;      // allow case sorting
        $opt['cols']      = 1;          // number of displayed columns (fixed for table layout, max for column layout
        $opt['dformat']   = "%d %b %Y"; // general display date format
        $opt['display']   = 'name';     // how page links should be displayed
        $opt['filter']    = [];    // filtering by metadata prior to sorting
        $opt['fontsize']  = '';         // base fontsize of pagequery; best to use %
        $opt['fullregex'] = false;      // power-user regex search option--file name only
        $opt['fulltext']  = false;      // search full-text; including file contents
        $opt['group']     = false;      // group the results based on sort headings
        $opt['hidejump']  = false;      // hide the jump to top link
        $opt['hidemsg']   = false;      // hide any error messages
        $opt['hidestart'] = false;      // hide start pages
        $opt['label']     = '';         // label to put at top of the list
        $opt['layout']    = 'table';    // html layout type: table (1 col = div only) or columns (html 5 only)
        $opt['limit']     = 0;          // limit results to certain number
        $opt['maxns']     = 0;          // max number of namespaces to display (i.e. ns depth)
        $opt['natsort']   = false;      // allow natural case sorting
        $opt['proper']    = 'none';     // display file names in Proper Case
        $opt['showcount'] = false;      // show the count of links found
        $opt['snippet']   = ['type' => 'none', 'count' => 0, 'extent' => '']; // show content snippets/abstracts
        $opt['sort']      = [];    // sort by various headings
        $opt['spelldate'] = false;      // spell out date headings in words where possible
        $opt['underline'] = false;      // faint underline below each link for clarity
        $opt['nstitle']   = false;      // internal use currently...

        foreach ($params as $param) {
            [$option, $value] = $this->keyvalue($param, '=');
            switch ($option) {
                case 'casesort':
                case 'fullregex':
                case 'fulltext':
                case 'group':
                case 'hidejump':
                case 'hidemsg':
                case 'hidestart':
                case 'natsort':
                case 'showcount':
                case 'spelldate':
                case 'underline':
                    $opt[$option] = true;
                    break;
                case 'limit':
                case 'maxns':
                    $opt[$option] = abs($value);
                    break;
                case 'sort':
                case 'filter':
                    $fields = explode(',', $value);
                    foreach ($fields as $field) {
                        [$key, $expr] = $this->keyvalue($field);
                        // allow for a few common naming differences
                        switch ($key) {
                            case 'pagename':
                                $key = 'name';
                                break;
                            case 'heading':
                            case 'firstheading':
                                $key = 'title';
                                break;
                            case 'pageid':
                            case 'id':
                                $key = 'id';
                                break;
                            case 'contrib':
                                $key = 'contributor';
                                break;
                        }
                        $opt[$option][$key] = $expr;
                    }
                    break;
                case 'proper':
                    switch ($value) {
                        case 'hdr':
                        case 'header':
                        case 'group':
                            $opt['proper'] = 'header';
                            break;
                        case 'name':
                        case 'page':
                            $opt['proper'] = 'name';
                            break;
                        default:
                            $opt['proper'] = 'both';
                    }
                    break;
                case 'cols':
                    $opt['cols'] = ($value > self::MAX_COLS) ? self::MAX_COLS : $value;
                    break;
                case 'border':
                    switch ($value) {
                        case 'both':
                        case 'inside':
                        case 'outside':
                        case 'none':
                            $opt['border'] = $value;
                            break;
                        default:
                            $opt['border'] = 'both';
                    }
                    break;
                case 'snippet':
                    $default = 'tooltip';
                    if (empty($value)) {
                        $opt['snippet']['type'] = $default;
                        break;
                    } else {
                        $options = explode(',', $value);
                        $type    = (empty($options[0])) ? $opt['snippet']['type'] : $options[0];
                        $count   = (empty($options[1])) ? $opt['snippet']['count'] : $options[1];
                        $extent  = (empty($options[2])) ? $opt['snippet']['extent'] : $options[2];

                        $valid = ['none', 'tooltip', 'inline', 'plain', 'quoted'];
                        if (!in_array($type, $valid)) {
                            $type = $default;  // empty snippet type => tooltip
                        }
                        $opt['snippet'] = ['type' => $type, 'count' => $count, 'extent' => $extent];
                        break;
                    }
                case 'label':
                case 'bullet':
                    $opt[$option] = $value;
                    break;
                case 'display':
                    switch ($value) {
                        case 'name':
                            $opt['display'] = 'name';
                            break;
                        case 'title':
                        case 'heading':
                        case 'firstheading':
                            $opt['display'] = 'title';
                            $opt['nstitle'] = true;
                            break;
                        case 'pageid':
                        case 'id':
                            $opt['display'] = 'id';
                            break;
                        default:
                            $opt['display'] = $value;
                    }
                    if (preg_match('/{(title|heading|firstheading)}/', $value)) {
                        $opt['nstitle'] = true;
                    }
                    break;
                case 'layout':
                    if (!in_array($value, ['table', 'column'])) {
                        $value = 'table';
                    }
                    $opt['layout'] = $value;
                    break;
                case 'fontsize':
                    if (!empty($value)) {
                        $opt['fontsize'] = $value;
                    }
                    break;
            }
        }
        return $opt;
    }

    /**
     * Split a string into key => value parts.
     *
     * @param string $str
     * @param string $delim
     */
    private function keyvalue(string $str, string $delim = ':'): array
    {
        $parts = explode($delim, $str);
        $key   = $parts[0] ?? '';
        $value = $parts[1] ?? '';
        return [$key, $value];
    }

    public function render($format, Doku_Renderer $renderer, $data): bool
    {
        $incl_ns    = [];
        $excl_ns    = [];
        $sort_opts  = [];
        $group_opts = [];
        $message    = '';

        $lang = ['jump_section' => $this->getLang('jump_section'), 'link_to_top'  => $this->getLang('link_to_top'),
            'no_results'   => $this->getLang('no_results')];
        require_once DOKU_PLUGIN . 'pagequery/PageQuery.php';
        $pq = new PageQuery($lang);

        $query = $data['query'];

        if ($format === 'xhtml') {
            // first get a raw list of matching results
            if ($data['fulltext']) {
                // full text searching (Dokuwiki style)
                $results = $pq->pageSearch($query);
            } else {
                // page id searching (i.e. namespace and name, faster)

                // fullregex option considers entire query to be a regex
                // over the whole page id, incl. namespace
                if (!$data['fullregex']) {
                    [$query, $incl_ns, $excl_ns] = $pq->parseNamespaceQuery($query);
                }

                // Allow for a lazy man's option!
                if ($query === '*') {
                    $query = '.*';
                }
                // search by page name or path only
                $results = $pq->pageLookup($query, $data['fullregex'], $incl_ns, $excl_ns);
            }
            $results = $pq->validatePages($results, $data['hidestart'], $data['maxns']);
            $no_result = false;
            $sort_array = [];
            if ($results == false) {
                $no_result = true;
                $message   = $this->getLang('regex_error');
            } elseif (!empty($results)) {
                // prepare the necessary sorting arrays, as per users options
                [$sort_array, $sort_opts, $group_opts] = $pq->buildSortingArray($results, $data);

                // meta data filtering of the list is next
                $sort_array = $pq->filterMetadata($sort_array, $data['filter']);
                if ($sort_array === []) {
                    $no_result = true;
                    $message   = $this->getLang("empty_filter");
                }
            } else {
                $no_result = true;
            }
            // successful search...
            if (!$no_result) {
                // now do the sorting
                $pq->msort($sort_array, $sort_opts);
                // limit the result list length if required; this can only be done after sorting!
                if ($data['limit'] > 0) {
                    $sort_array = array_slice($sort_array, 0, $data['limit']);
                }
                // do a link count BEFORE grouping (don't want to count headers...)
                $count = count($sort_array);
                // and finally the grouping
                $keys = ['name', 'id', 'title', 'abstract', 'display'];
                if (!$data['group']) {
                    $group_opts = [];
                }
                $sorted_results = $pq->mgroup($sort_array, $keys, $group_opts);
                // and out to the page
                $renderer->doc .= $pq->renderAsHtml($data['layout'], $sorted_results, $data, $count);
                // no results...
            } elseif (!$data['hidemsg']) {
                $renderer->doc .= $pq->renderAsEmpty($query, $message);
            }
            return true;
        } elseif ($format === 'metadata') {
            // this is a pagequery page needing PARSER_CACHE_USE event trigger;
            $renderer->meta['pagequery'] = true;
            unset($renderer->persistent['pagequery']);
            return true;
        } else {
            return false;
        }
    }
}
