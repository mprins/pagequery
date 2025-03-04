<?php

use dokuwiki\Utf8\PhpString;
use dokuwiki\File\PageResolver;

class PageQuery
{
    public const MSORT_KEEP_ASSOC = 'msort01';
    public const MSORT_NUMERIC = 'msort02';
    public const MSORT_REGULAR = 'msort03';
    public const MSORT_STRING = 'msort04';
    public const MSORT_STRING_CASE = 'msort05';
    public const MSORT_NAT = 'msort06';
    public const MSORT_NAT_CASE = 'msort07';
    public const MSORT_ASC = 'msort08';
    public const MSORT_DESC = 'msort09';
    public const MSORT_DEFAULT_DIRECTION = self::MSORT_ASC;
    public const MSORT_DEFAULT_TYPE = self::MSORT_STRING;
    public const MGROUP_NONE = 'mgrp00';
    public const MGROUP_HEADING = 'mgrp01';
    public const MGROUP_NAMESPACE = 'mgrp02';
    public const MGROUP_REALDATE = '__realdate__';
    private $lang;

    // returns first $count letters from $text in lowercase
    private $snippet_cnt = 0;

    public function __construct(array $lang)
    {
        $this->lang = $lang;
    }

    /**
     * Render a simple "no results" message
     *
     * @param string $query => original query
     * @param string $error
     */
    final public function renderAsEmpty($query, $error = ''): string
    {
        $render = '<div class="pagequery no-border">' . DOKU_LF;
        $render .= '<p class="no-results"><span>pagequery</span>' . sprintf(
            $this->lang["no_results"],
            '<strong>' . $query . '</strong>'
        ) . '</p>' . DOKU_LF;
        if (!empty($error)) {
            $render .= '<p class="no-results">' . $error . '</p>' . DOKU_LF;
        }
        $render .= '</div>' . DOKU_LF;
        return $render;
    }

    final public function renderAsHtml(string $layout, $sorted_results, $opt, $count)
    {
        $this->snippet_cnt = $opt['snippet']['count'];
        $render_type       = 'renderAsHtml' . $layout;
        return $this->$render_type($sorted_results, $opt, $count);
    }

    /**
     * Parse out the namespace, and convert to a regex for array search.
     *
     * @param string $query user page query
     * @return array        processed query with necessary regex markup for namespace recognition
     */
    final public function parseNamespaceQuery(string $query): array
    {
        $incl_ns  = [];
        $excl_ns  = [];
        $page_qry = '';
        $tokens   = explode(' ', trim($query));
        if (count($tokens) == 1) {
            $page_qry = $query;
        } else {
            foreach ($tokens as $token) {
                if (preg_match('/^(?:\^|-ns:)(.+)$/u', $token, $matches)) {
                    $excl_ns[] = $this->resolveNamespace($matches[1]);
                } elseif (preg_match('/^(?:@|ns:)(.+)$/u', $token, $matches)) {
                    $incl_ns[] = $this->resolveNamespace($matches[1]);
                } else {
                    $page_qry .= ' ' . $token;
                }
            }
        }
        $page_qry = trim($page_qry);
        return [$page_qry, $incl_ns, $excl_ns];
    }

    /**
     * Builds the sorting array: array of arrays (0 = id, 1 = name, 2 = abstract, 3 = ... , etc)
     *
     * @param array $ids array of page ids to be sorted
     * @param array $opt all user options/settings
     *
     * @return array    $sort_array     array of array(one value for each key to be sorted)
     *                  $sort_opts      sorting options for the msort function
     *                  $group_opts     grouping options for the mgroup function
     */
    final public function buildSortingArray(array $ids, array $opt): array
    {
        global $conf;

        $sort_array = [];
        $sort_opts  = [];
        $group_opts = [];

        $dformat = [];
        $wformat = [];

        $cnt = 0;

        // look for 'abc' by title instead of name ('abc' by page-id makes little sense)
        // title takes precedence over name (should you try to sort by both...why?)
        $from_title = isset($opt['sort']['title']);

        // is it necessary to cache the abstract column?
        $get_abstract = ($opt['snippet']['type'] !== 'none');

        // add any extra columns needed for filtering!
        $extrakeys = array_diff_key($opt['filter'], $opt['sort']);
        $col_keys  = array_merge($opt['sort'], $extrakeys);

        // it is more efficient to get all the back-links at once from the indexer metadata
        if (isset($col_keys['backlinks'])) {
            $backlinks = idx_get_indexer()->lookupKey('relation_references', $ids);
        }

        foreach ($ids as $id) {
            // getting metadata is very time-consuming, hence ONCE per displayed row
            $meta = p_get_metadata($id, '', METADATA_DONT_RENDER);

            if (!isset($meta['date']['created'])) {
                $meta['date']['created'] = 0;
            }
            if (!isset($meta['date']['modified'])) {
                $meta['date']['modified'] = $meta['date']['created'];
            }
            // establish page name (without namespace)
            $name = noNS($id);

            // ref to current row, used through out function
            $row = &$sort_array[$cnt];

            // first column is the basic page id
            $row['id'] = $id;

            // second column is the display 'name' (used when sorting by 'name')
            // this also avoids rebuilding the display name when building links later (DRY)
            $row['name'] = $name;

            // third column: cache the display name; taken from metadata => 1st heading
            // used when sorting by 'title'
            $title        = (isset($meta['title']) && !empty($meta['title'])) ? $meta['title'] : $name;
            $row['title'] = $title;

            // needed later in the a, ab ,abc clauses
            $abc = ($from_title) ? $title : $name;

            // fourth column: cache the page abstract if needed; this saves a lot of time later
            // and avoids repeated slow metadata retrievals (v. slow!)
            $abstract        = ($get_abstract) ? $meta['description']['abstract'] : '';
            $row['abstract'] = $abstract;

            // fifth column is the displayed text for links; set below
            $row['display'] = '';

            // reset cache of full date for this row
            $real_date = 0;

            // ...optional columns
            foreach (array_keys($col_keys) as $key) {
                $value = '';
                switch ($key) {
                    case 'a':
                    case 'ab':
                    case 'abc':
                        $value = $this->first($abc, strlen($key));
                        break;
                    case 'name':
                    case 'title':
                        // name/title columns already exists by default (col 1,2)
                        // save a few microseconds by just moving on to the next key
                        continue 2;
                    case 'id':
                        $value = $id;
                        break;
                    case 'ns':
                        $value = getNS($id);
                        if (empty($value)) {
                            $value = '[' . $conf['start'] . ']';
                        }
                        break;
                    case 'creator':
                        $value = $meta['creator'];
                        break;
                    case 'contributor':
                        $value = implode(' ', $meta['contributor']);
                        break;
                    case 'mdate':
                        $value = $meta['date']['modified'];
                        break;
                    case 'cdate':
                        $value = $meta['date']['created'];
                        break;
                    case 'links':
                        $value = $this->joinKeysIf(' ', $meta['relation']['references']);
                        break;
                    case 'backlinks':
                        $value = implode(' ', current($backlinks));
                        next($backlinks);
                        break;
                    default:
                        // date sorting types (groupable)
                        $dtype = $key[0];
                        if ($dtype === 'c' || $dtype === 'm') {
                            // we only set real date once per id (needed for grouping)
                            // not per sort column--the date should remain same across all columns
                            // this is always the last column!
                            if ($real_date == 0) {
                                $real_date                  = ($dtype === 'c') ?
                                    $meta['date']['created'] : $meta['date']['modified'];
                                $row[self::MGROUP_REALDATE] = $real_date;
                            }
                            // only set date formats once per sort column/key (not per id!), i.e. on first row
                            if ($cnt == 0) {
                                $dformat[$key] = $this->dateFormat($key);
                                // collect date in word format for potential use in grouping
                                $wformat[$key] = ($opt['spelldate']) ? $this->dateFormatWords($dformat[$key]) : '';
                            }
                            // create a string date used for sorting only
                            // (we cannot just use the real date otherwise it would not group correctly)
                            $value = strftime($dformat[$key], $real_date);
                        }
                }
                // set the optional column
                $row[$key] = $value;
            }

            /* provide custom display formatting via string templating {...} */

            $matches = [];
            $display = $opt['display'];
            $matched = preg_match_all('/\{(.+?)\}/', $display, $matches, PREG_SET_ORDER);

            // first try to use the custom column names as entered by user
            if ($matched > 0) {
                foreach ($matches as $match) {
                    $key   = $match[1];
                    $value = null;
                    if (isset($row[$key])) {
                        $value = $row[$key];
                    } elseif (isset($meta[$key])) {
                        $value = $meta[$key];
                        // allow for nested meta keys (e.g. date:created)
                    } elseif (strpos($key, ':') !== false) {
                        $keys = explode(':', $key);
                        if (isset($meta[$keys[0]][$keys[1]])) {
                            $value = $meta[$keys[0]][$keys[1]];
                        }
                    } elseif ($key === 'mdate') {
                        $value = $meta['date']['modified'];
                    } elseif ($key === 'cdate') {
                        $value = $meta['date']['created'];
                    }
                    if (!is_null($value)) {
                        if (strpos($key, 'date') !== false && $value != '') {
                            $value = utf8_encode(strftime($opt['dformat'], $value));
                        }
                        $display = str_replace($match[0], $value, $display);
                    }
                }

                // try to match any metadata field; to allow for plain single word display settings
                // e.g. display=title or display=name
            } elseif (isset($row[$display])) {
                $display = $row[$display];
                // if all else fails then use the page name (always available)
            } else {
                $display = $row['name'];
            }
            $row['display'] = $display;

            $cnt++;
        }

        $idx = 0;
        foreach ($opt['sort'] as $key => $value) {
            $sort_opts['key'][] = $key;

            // now the sort direction
            switch ($value) {
                case 'a':
                case 'asc':
                    $dir = self::MSORT_ASC;
                    break;
                case 'd':
                case 'desc':
                    $dir = self::MSORT_DESC;
                    break;
                default:
                    switch ($key) {
                        // sort dates descending by default; text ascending
                        case 'a':
                        case 'ab':
                        case 'abc':
                        case 'name':
                        case 'title':
                        case 'id':
                        case 'ns':
                        case 'creator':
                        case 'contributor':
                            $dir = self::MSORT_ASC;
                            break;
                        default:
                            $dir = self::MSORT_DESC;
                            break;
                    }
            }
            $sort_opts['dir'][] = $dir;

            // set the sort array's data type
            switch ($key) {
                case 'mdate':
                case 'cdate':
                    $type = self::MSORT_NUMERIC;
                    break;
                default:
                    if ($opt['casesort']) {
                        // case sensitive: a-z then A-Z
                        $type = ($opt['natsort']) ? self::MSORT_NAT : self::MSORT_STRING;
                    } else {
                        // case-insensitive
                        $type = ($opt['natsort']) ? self::MSORT_NAT_CASE : self::MSORT_STRING_CASE;
                    }
            }
            $sort_opts['type'][] = $type;

            // now establish grouping options
            switch ($key) {
                // name strings and full dates cannot be meaningfully grouped (no duplicates!)
                case 'mdate':
                case 'cdate':
                case 'name':
                case 'title':
                case 'id':
                    $group_by = self::MGROUP_NONE;
                    break;
                case 'ns':
                    $group_by = self::MGROUP_NAMESPACE;
                    break;
                default:
                    $group_by = self::MGROUP_HEADING;
            }
            if ($group_by !== self::MGROUP_NONE) {
                $group_opts['key'][$idx]     = $key;
                $group_opts['type'][$idx]    = $group_by;
                $group_opts['dformat'][$idx] = $wformat[$key] ?? '';
                $idx++;
            }
        }

        return [$sort_array, $sort_opts, $group_opts];
    }

    private function first(string $text, $count): string
    {
        return ($count > 0) ? PhpString::substr(PhpString::strtolower($text), 0, $count) : '';
    }

    private function joinKeysIf($delim, $arr)
    {
        $result = '';
        if (!empty($arr)) {
            foreach ($arr as $key => $value) {
                if ($value === true) {
                    $result .= $key . $delim;
                }
            }
            if (!empty($result)) {
                $result = substr($result, 0, -1);
            }
        }
        return $result;
    }

    /**
     * Parse the c|m-year-month-day option; used for sorting/grouping
     *
     * @param string $key
     */
    private function dateFormat(string $key): string
    {
        $dkey = [];
        if (strpos($key, 'year') !== false) {
            $dkey[] = '%Y';
        }
        if (strpos($key, 'month') !== false) {
            $dkey[] = '%m';
        }
        if (strpos($key, 'day') !== false) {
            $dkey[] = '%d';
        }
        return implode('-', $dkey);
    }

    /**
     * Provide month and day format in real words if required
     * used for display only ($dformat is used for sorting/grouping)
     *
     * @param string $dformat
     */
    private function dateFormatWords(string $dformat): string
    {
        $wformat = '';
        switch ($dformat) {
            case '%m':
                $wformat = "%B";
                break;
            case '%d':
                $wformat = "%#d–%A ";
                break;
            case '%Y-%m':
                $wformat = "%B %Y";
                break;
            case '%m-%d':
                $wformat = "%B %#d, %A ";
                break;
            case '%Y-%m-%d':
                $wformat = "%A, %B %#d, %Y";
                break;
        }
        return $wformat;
    }

    /**
     * Just a wrapper around the Dokuwiki pageSearch function.
     *
     * @param string $query
     * @return int[]|string[]
     */
    final public function pageSearch(string $query): array
    {
        $highlight = [];
        return array_keys(ft_pageSearch($query, $highlight));
    }

    /**
     * A heavily customised version of _ft_pageLookup in inc/fulltext.php
     * no sorting!
     */
    final public function pageLookup($query, $fullregex, $incl_ns, $excl_ns)
    {
        global $conf;

        $query = trim($query);
        $pages = file($conf['indexdir'] . '/page.idx');

        if (!$fullregex) {
            // first deal with excluded namespaces, then included, order matters!
            $pages = $this->filterNamespaces($pages, $excl_ns, true);
            $pages = $this->filterNamespaces($pages, $incl_ns, false);
        }

        foreach ($pages as $i => $iValue) {
            $page = $iValue;
            if (!page_exists($page) || isHiddenPage($page)) {
                unset($pages[$i]);
                continue;
            }
            if (!$fullregex) {
                $page = noNS($page);
            }
            /*
             * This is the actual "search" expression.
             * Note: preg_grep cannot be used because we need to
             *  allow for beginning of string "^" regex on normal page search
             *  and the page-exists check above
             * The @ prevents problems with invalid queries!
             */
            $matched = @preg_match('/' . $query . '/i', $page);
            if ($matched === false) {
                return false;
            } elseif ($matched == 0) {
                unset($pages[$i]);
            }
        }
        if (count($pages) > 0) {
            return $pages;
        } else {
            // we always return an array type
            return [];
        }
    }

    /**
     * Include/Exclude specific namespaces from a list of pages.
     * @param array  $pages   a list of wiki page ids
     * @param array  $ns_qry  namespace(s) to include/exclude
     * @param string $exclude true = exclude
     */
    private function filterNamespaces(array $pages, array $ns_qry, string $exclude): array
    {
        $invert = ($exclude) ? PREG_GREP_INVERT : 0;
        foreach ($ns_qry as $ns) {
            //  we only look for namespace from beginning of the id
            $regexes[] = '^' . $ns . ':.*';
        }
        if (!empty($regexes)) {
            $regex  = '/(' . implode('|', $regexes) . ')/';
            $result = array_values(preg_grep($regex, $pages, $invert));
        } else {
            $result = $pages;
        }
        return $result;
    }

    final public function validatePages(array $pages, bool $nostart = true, int $maxns = 0): array
    {
        global $conf;

        $pages = array_map('trim', $pages);

        // check ACL permissions, too many ns levels, and remove any 'start' pages as needed
        $start  = $conf['start'];
        $offset = strlen($start);
        foreach ($pages as $idx => $name) {
            if ($nostart && substr($name, -$offset) == $start) {
                unset($pages[$idx]);
            } elseif ($maxns > 0 && (substr_count($name, ':')) > $maxns) {
                unset($pages[$idx]);
                // TODO: this function is one of slowest in the plugin; solutions?
            } elseif (auth_quickaclcheck($pages[$idx]) < AUTH_READ) {
                unset($pages[$idx]);
            }
        }
        return $pages;
    }

    /**
     * filter array of pages by specific meta data keys (or columns)
     *
     * @param array $sort_array full sorting array, all meta columns included
     * @param array $filter     meta-data filter: <meta key>:<query>
     */
    final public function filterMetadata(array $sort_array, array $filter): array
    {
        foreach ($filter as $metakey => $expr) {
            // allow for exclusion matches (put ^ or ! in front of meta key)
            $exclude = false;
            if ($metakey[0] === '^' || $metakey[0] === '!') {
                $exclude = true;
                $metakey = substr($metakey, 1);
            }
            $that       = $this;
            $sort_array = array_filter($sort_array, static function ($row) use ($metakey, $expr, $exclude, $that) {
                if (!isset($row[$metakey])) {
                    return false;
                }
                if (strpos($metakey, 'date') !== false) {
                    $match = $that->filterByDate($expr, $row[$metakey]);
                } else {
                    $match = preg_match('`' . $expr . '`', $row[$metakey]) > 0;
                }
                if ($exclude) {
                    $match = !$match;
                }
                return $match;
            });
        }
        return $sort_array;
    }

    private function filterByDate($filter, $date): bool
    {
        $filter  = str_replace('/', '.', $filter);  // allow for Euro style date formats
        $filters = explode('->', $filter);
        $begin   = (empty($filters[0]) ? null : strtotime($filters[0]));
        $end     = (empty($filters[1]) ? null : strtotime($filters[1]));

        $matched = false;
        if ($begin !== null && $end !== null) {
            $matched = ($date >= $begin && $date <= $end);
        } elseif ($begin !== null) {
            $matched = ($date >= $begin);
        } elseif ($end !== null) {
            $matched = ($date <= $end);
        }
        return $matched;
    }

    /**
     * A replacement for "array_mulitsort" which permits natural and case-less sorting
     * This function will sort an 'array of rows' only (not array of 'columns')
     *
     * @param array $sort_array                 : multi-dimensional array of arrays, where the first index refers to
     *                                          the row number and the second to the column number
     *                                          (e.g. $array[row_number][column_number])
     *                                          i.e. = array(
     *                                          array('name1', 'job1', 'start_date1', 'rank1'),
     *                                          array('name2', 'job2', 'start_date2', 'rank2'),
     *                                          ...
     *                                          );
     *
     * @param mixed $sort_opts                  : options for how the array should be sorted
     *                                          :AS ARRAY
     *                                          $sort_opts['key'][<column>] = 'key'
     *                                          $sort_opts['type'][<column>] = 'type'
     *                                          $sort_opts['dir'][<column>] = 'dir'
     *                                          $sort_opts['assoc'][<column>] = MSORT_KEEP_ASSOC | true
     */
    final public function msort(array &$sort_array, $sort_opts): bool
    {
        // if a full sort_opts array was passed
        $keep_assoc = false;
        if (is_array($sort_opts) && $sort_opts !== []) {
            if (isset($sort_opts['assoc'])) {
                $keep_assoc = true;
            }
        } else {
            return false;
        }

        // Determine which u..sort function (with or without associations).
        $sort_func = ($keep_assoc) ? 'uasort' : 'usort';

        $keys = $sort_opts['key'];

        // HACK: self:: does not work inside a closure so...
        $self = self::class;

        // Sort the data and get the result.
        $result = $sort_func($sort_array, function (array $left, array $right) use ($sort_opts, $keys, $self) {
            // Assume that the entries are the same.
            $cmp = 0;

            // Work through each sort column
            foreach ($keys as $idx => $key) {
                // Handle the different sort types.
                switch ($sort_opts['type'][$idx]) {
                    case $self::MSORT_NUMERIC:
                        $key_cmp = (((int)$left[$key] === (int)$right[$key]) ?
                            0 : (((int)$left[$key] < (int)$right[$key]) ? -1 : 1));
                        break;

                    case $self::MSORT_STRING:
                        $key_cmp = strcmp((string)$left[$key], (string)$right[$key]);
                        break;

                    case $self::MSORT_STRING_CASE: //case-insensitive
                        $key_cmp = strcasecmp((string)$left[$key], (string)$right[$key]);
                        break;

                    case $self::MSORT_NAT:
                        $key_cmp = strnatcmp((string)$left[$key], (string)$right[$key]);
                        break;

                    case $self::MSORT_NAT_CASE:    //case-insensitive
                        $key_cmp = strnatcasecmp((string)$left[$key], (string)$right[$key]);
                        break;

                    case $self::MSORT_REGULAR:
                    default:
                        $key_cmp = (($left[$key] == $right[$key]) ?
                            0 : (($left[$key] < $right[$key]) ? -1 : 1));
                        break;
                }

                // Is the column in the two arrays the same?
                if ($key_cmp == 0) {
                    continue;
                }

                // Are we sorting descending?
                $cmp = $key_cmp * (($sort_opts['dir'][$idx] === $self::MSORT_DESC) ? -1 : 1);

                // no need for remaining keys as there was a difference
                break;
            }
            return $cmp;
        });
        return $result;
    }

    /**
     * group a multi-dimensional array by each level heading
     * @param array $sort_array    : array to be grouped (result of 'msort' function)
     *                             __realdate__' column should contain real dates if you need dates in words
     * @param array $keys          : which keys (columns) should be returned in results array? (as keys)
     * @param mixed $group_opts    :  AS ARRAY:
     *                             $group_opts['key'][<order>] = column key to group by
     *                             $group_opts['type'][<order>] = grouping type [MGROUP...]
     *                             $group_opts['dformat'][<order>] = date formatting string
     *
     * @return array $results   : array of arrays: (level, name, page_id, title), e.g. array(1, 'Main Title')
     *                              array(0, '...') =>  0 = normal row item (not heading)
     */
    final public function mgroup(array $sort_array, array $keys, $group_opts = []): array
    {
        $prevs   = [];
        $results = [];
        $idx     = 0;

        if ($sort_array === []) {
            $results = [];
        } elseif (empty($group_opts)) {
            foreach ($sort_array as $row) {
                $result = [0];
                foreach ($keys as $key) {
                    $result[] = $row[$key];
                }
                $results[] = $result;
            }
        } else {
            $level = count($group_opts['key']) - 1;
            foreach ($sort_array as $row) {
                $this->addHeading($results, $sort_array, $group_opts, $level, $idx, $prevs);
                $result = [0]; // basic item (page link) is level 0
                foreach ($keys as $iValue) {
                    $result[] = $row[$iValue];
                }
                $results[] = $result;
                $idx++;
            }
        }
        return $results;
    }

    /**
     * private function used by mgroup only!
     */
    private function addHeading(&$results, $sort_array, $group_opts, $level, $idx, &$prevs): void
    {
        global $conf;

        // recurse to find all parent headings
        if ($level > 0) {
            $this->addHeading($results, $sort_array, $group_opts, $level - 1, $idx, $prevs);
        }
        $group_type = $group_opts['type'][$level];

        $prev = $prevs[$level] ?? '';
        $key  = $group_opts['key'][$level];
        $cur  = $sort_array[$idx][$key];
        if ($cur != $prev) {
            $prevs[$level] = $cur;

            if ($group_type === self::MGROUP_HEADING) {
                $date_format = $group_opts['dformat'][$level];
                if (!empty($date_format)) {
                    // the real date is always the '__realdate__' column (MGROUP_REALDATE)
                    $cur = strftime($date_format, $sort_array[$idx][self::MGROUP_REALDATE]);
                }
                // args : $level, $name, $id, $_, $abstract, $display
                $results[] = [$level + 1, $cur, ''];
            } elseif ($group_type === self::MGROUP_NAMESPACE) {
                $cur_ns  = explode(':', $cur);
                $prev_ns = explode(':', $prev);
                // only show namespaces that are different from the previous heading
                for ($i = 0, $iMax = count($cur_ns); $i < $iMax; $i++) {
                    if ($cur_ns[$i] !== $prev_ns[$i]) {
                        $hl = $level + $i + 1;
                        $id = implode(':', array_slice($cur_ns, 0, $i + 1)) . ':' . $conf['start'];
                        if (page_exists($id)) {
                            $ns_start = $id;
                            // allow the first heading to be used instead of page id/name
                            $display = p_get_metadata($id, 'title');
                        } else {
                            $ns_start = '';
                            $display = '';
                        }
                        // args : $level, $name, $id, $_, $abstract, $display
                        $results[] = [$hl, $cur_ns[$i], $ns_start, '', '', $display];
                    }
                }
            }
        }
    }

    /**
     * Render the final pagequery results list as HTML, indented and in columns as required.
     *
     * **DEPRECATED** --- I would like to scrap this ASAP (old browsers only).
     * It's complicated and it's hard to maintain.
     *
     * @param array $sorted_results
     * @param array $opt
     * @param int   $count => count of results
     * @return string => HTML rendered list
     */
    protected function renderAsHtmltable($sorted_results, $opt, $count): string
    {
        $ratios           = [.80, 1.3, 1.17, 1.1, 1.03, .96, .90];   // height ratios: link, h1, h2, h3, h4, h5, h6
        $render           = '';
        $prev_was_heading = false;
        $can_start_col    = true;
        $cont_level       = 1;
        $col              = 0;
        $multi_col        = $opt['cols'] > 1;  // single columns are displayed without tables (better for TOC)
        $col_height       = $this->adjustedHeight($sorted_results, $ratios) / $opt['cols'];
        $cur_height       = 0;
        $width            = floor(100 / $opt['cols']);
        $is_first         = true;
        $fontsize         = '';
        $list_style       = '';
        $indent_style     = '';

        // basic result page markup (always needed)
        $outer_border = ($opt['border'] === 'outside' || $opt['border'] === 'both') ? 'border' : '';
        $inner_border = ($opt['border'] === 'inside' || $opt['border'] === 'both') ? 'border' : '';
        $tableless    = ($multi_col) ? '' : 'tableless';

        // fixed anchor point to jump back to at top of the table
        $top_id = 'top-' . random_int(0, mt_getrandmax());

        if (!empty($opt['fontsize'])) {
            $fontsize = 'font-size:' . $opt['fontsize'];
        }
        if ($opt['bullet'] !== 'none') {
            $list_style = 'list-style-position:inside;list-style-type:' . $opt['bullet'];
        }
        $can_indent = $opt['group'];

        $render .= '<div class="pagequery ' . $outer_border . " " . $tableless . '" id="' . $top_id . '" style="'
            . $fontsize . '">' . DOKU_LF;

        if ($opt['showcount'] === true) {
            $render .= '<div class="count">' . $count . '</div>' . DOKU_LF;
        }
        if ($opt['label'] !== '') {
            $render .= '<h1 class="title">' . $opt['label'] . '</h1>' . DOKU_LF;
        }
        if ($multi_col) {
            $render .= '<table><tbody><tr>' . DOKU_LF;
        }

        // now render the pagequery list
        foreach ($sorted_results as $line) {
            $level = $line[0] ?? '';
            $name = $line[1] ?? '';
            $id = $line[2] ?? '';
            $_ = $line[3] ?? '';
            $abstract = $line[4] ?? '';
            $display = $line[5] ?? '';

            $heading    = '';
            $is_heading = ($level > 0);
            if ($is_heading) {
                $heading = $name;
            }

            // is it time to start a new column?
            if ($can_start_col === false && $col < $opt['cols'] && $cur_height >= $col_height) {
                $can_start_col = true;
                $col++;
            }

            // no need for indenting if there is no grouping
            if ($can_indent) {
                $indent       = ($is_heading) ? $level - 1 : $cont_level - 1;
                $indent_style = 'margin-left:' . $indent * 10 . 'px;';
            }

            // Begin new column if: 1) we are at the start, 2) last item was not a heading or 3) if there is no grouping
            if (
                $can_start_col
                && $prev_was_heading === false
            ) {
                $jump_tip = sprintf($this->lang['jump_section'], $heading);
                // close the previous column if necessary; also adds a 'jump to anchor'
                $col_close     = ($is_heading) ?
                    '' : '<a title="' . $jump_tip . '" href="#' . $top_id . '">' . "</a>";
                $col_close     = ($is_first) ? '' : $col_close . '</ul></td>' . DOKU_LF;
                $col_open      = (!$is_first && !$is_heading) ? '<h' . $cont_level . ' style="' . $indent_style . '">'
                    . $heading . '...</h' . $cont_level . '>' : '';
                $td            = ($multi_col) ? '<td class="' . $inner_border . '" valign="top" width="'
                    . $width . '%">' : '';
                $render        .= $col_close . $td . $col_open . DOKU_LF;
                $can_start_col = false;

                // needed to correctly style page link lists <ul>...
                $prev_was_heading = true;
                $cur_height       = 0;
            }

            // finally display the appropriate heading or page link(s)
            if ($is_heading) {
                // close previous sub list if necessary
                if (!$prev_was_heading) {
                    $render .= '</ul>' . DOKU_LF;
                }
                if ($opt['nstitle'] && !empty($display)) {
                    $heading = $display;
                }
                if ($opt['proper'] === 'header' || $opt['proper'] === 'both') {
                    $heading = $this->proper($heading);
                }
                if (!empty($id)) {
                    $heading = $this->htmlWikilink($id, $heading, '', $opt, false, true);
                }
                $render           .= '<a title="' . $jump_tip . '" href="#' . $top_id . '">
                    <h' . $level . ' style="' . $indent_style . '">' . $heading
                    . '</h' . $level . '></a>' . DOKU_LF;
                $prev_was_heading = true;
                $cont_level       = $level + 1;
            } else {
                // open a new sub list if necessary
                if ($prev_was_heading || $is_first) {
                    $render .= '<ul style="' . $indent_style . $list_style . '">';
                }
                // deal with normal page links
                if ($opt['proper'] === 'name' || $opt['proper'] === 'both') {
                    $display = $this->proper($display);
                }
                $link             = $this->htmlWikilink($id, $display, $abstract, $opt);
                $render           .= $link;
                $prev_was_heading = false;
            }
            $cur_height += $ratios[$level];
            $is_first   = false;
        }
        $render .= '</ul>' . DOKU_LF;
        if ($multi_col) {
            $render .= '</td></tr></tbody></table>' . DOKU_LF;
        }
        if ($opt['hidejump'] === false) {
            $render .= '<a class="top" href="#' . $top_id . '">' . $this->lang['link_to_top'] . '</a>' . DOKU_LF;
        }
        $render .= '</div>' . DOKU_LF;

        return $render;
    }

    /**
     * Used by the render_as_html_table function below
     * **DEPRECATED**
     *
     * @param $sorted_results
     * @param $ratios
     */
    private function adjustedHeight($sorted_results, $ratios): int
    {
        // ratio of different heading heights (%), to ensure more even use of columns (h1 -> h6)
        $adjusted_height = 0;
        foreach ($sorted_results as $row) {
            $adjusted_height += $ratios[$row[0]];
        }
        return $adjusted_height;
    }

    /**
     * Changes a wiki page id into proper case (allowing for :'s etc...)
     * @param string $id page id
     */
    private function proper(string $id): string
    {
        $id = str_replace(':', ': ', $id); // make a little whitespace before words so ucwords can work!
        $id = str_replace('_', ' ', $id);
        $id = ucwords($id);
        return str_replace(': ', ':', $id);
    }

    /**
     * Renders the page link, plus tooltip, abstract, casing, etc...
     * @param string $id
     * @param string $display
     * @param string $abstract
     * @param array  $opt
     * @param bool   $track_snippets
     * @param bool   $raw non-formatted (no html)
     */
    private function htmlWikilink(
        string $id,
        string $display,
        string $abstract,
        array $opt,
        bool $track_snippets = true,
        bool $raw = false
    ): string {
        $id = (strpos($id, ':') === false) ? ':' . $id : $id;   // : needed for root pages (root level)
        $link   = html_wikilink($id, $display);
        $type   = $opt['snippet']['type'];
        $inline = '';
        $after  = '';

        if ($type === 'tooltip') {
            $tooltip = str_replace("\n\n", "\n", $abstract);
            $tooltip = htmlentities($tooltip, ENT_QUOTES, 'UTF-8');
            $link    = $this->addTooltip($link, $tooltip);
        } elseif (in_array($type, ['quoted', 'plain', 'inline']) && $this->snippet_cnt > 0) {
            $short = $this->shorten($abstract, $opt['snippet']['extent']);
            $short = htmlentities($short, ENT_QUOTES, 'UTF-8');
            if (!empty($short)) {
                if ($type === 'quoted' || $type === 'plain') {
                    $more  = html_wikilink($id, 'more');
                    $after = trim($short);
                    $after = str_replace("\n\n", "\n", $after);
                    $after = str_replace("\n", '<br/>', $after);
                    $after = '<div class="' . $type . '">' . $after . $more . '</div>' . DOKU_LF;
                } elseif ($type === 'inline') {
                    $inline .= '<span class=inline>' . $short . '</span>';
                }
            }
        }

        $border = ($opt['underline']) ? 'border' : '';
        if ($raw) {
            $wikilink = $link . $inline;
        } else {
            $wikilink = '<li class="' . $border . '">' . $link . $inline . DOKU_LF . $after . '</li>';
        }
        if ($track_snippets) {
            $this->snippet_cnt--;
        }
        return $wikilink;
    }

    /**
     * Swap normal link title (popup) for a more useful preview
     *
     * @param string $link
     * @param string $tooltip title
     * @return string   complete href link
     */
    private function addTooltip(string $link, string $tooltip): string
    {
        $tooltip = str_replace("\n", '  ', $tooltip);
        return preg_replace('/title=\".+?\"/', 'title="' . $tooltip . '"', $link, 1);
    }

    // real date column
    /**
     * Return the first part of the text according to the extent given.
     *
     * @param string $text
     * @param string $extent c? = ? chars, w? = ? words, l? = ? lines, ~? = search up to text/char/symbol
     * @param string $more   symbol to show if more text
     */
    private function shorten(string $text, string $extent, string $more = '... '): string
    {
        $elem = $extent[0];
        $cnt  = (int)substr($extent, 1);
        switch ($elem) {
            case 'c':
                $result = substr($text, 0, $cnt);
                if ($cnt > 0 && strlen($result) < strlen($text)) {
                    $result .= $more;
                }
                break;
            case 'w':
                $words  = str_word_count($text, 1, '.');
                $result = implode(' ', array_slice($words, 0, $cnt));
                if ($cnt > 0 && $cnt <= count($words) && $words[$cnt - 1] !== '.') {
                    $result .= $more;
                }
                break;
            case 'l':
                $lines  = explode("\n", $text);
                $lines  = array_filter($lines);  // remove blank lines
                $result = implode("\n", array_slice($lines, 0, $cnt));
                if ($cnt > 0 && $cnt < count($lines)) {
                    $result .= $more;
                }
                break;
            case "~":
                $result = strstr($text, (string) $cnt, true);
                break;
            default:
                $result = $text;
        }
        return $result;
    }

    /**
     * Render the final pagequery results list in an HTML column, indented and in columns as required
     *
     * @param array $sorted_results
     * @param array $opt
     * @param int   $count => count of results
     *
     * @return string HTML rendered list
     */
    protected function renderAsHtmlcolumn(array $sorted_results, array $opt, int $count): string
    {
        $prev_was_heading = false;
        $cont_level       = 1;
        $is_first         = true;
        $top_id           = 'top-' . random_int(0, mt_getrandmax());   // A fixed anchor at top to jump back to

        // CSS for the various display options
        $fontsize     = (empty($opt['fontsize'])) ? '' : 'font-size:' . $opt['fontsize'];
        $outer_border = ($opt['border'] === 'outside' || $opt['border'] === 'both') ? 'border' : '';
        $inner_border = ($opt['border'] === 'inside' || $opt['border'] === 'both') ? 'inner-border' : '';
        $show_count   = ($opt['showcount'] === true) ? '<div class="count">' . $count . ' ∞</div>' . DOKU_LF : '';
        $label        = ($opt['label'] !== '') ? '<h1 class="title">' . $opt['label'] . '</h1>' . DOKU_LF : '';
        $show_jump    = ($opt['hidejump'] === false) ? '<a class="top" href="#' . $top_id . '">'
            . $this->lang['link_to_top'] . '</a>' . DOKU_LF : '';
        $list_style   = ($opt['bullet'] !== 'none') ? 'list-style-position:inside;list-style-type:'
            . $opt['bullet'] : '';

        // no grouping => no indenting
        $can_indent = $opt['group'];

        // now prepare the actual pagequery list
        $pagequery = '';
        foreach ($sorted_results as $line) {
            [$level, $name, $id, $_, $abstract, $display] = $line;

            $is_heading = ($level > 0);
            $heading    = ($is_heading) ? $name : '';

            if ($can_indent) {
                $indent       = ($is_heading) ? $level - 1 : $cont_level - 1;
                $indent_style = 'margin-left:' . $indent * 10 . 'px;';
            } else {
                $indent_style = '';
            }

            // finally display the appropriate heading...
            if ($is_heading) {
                // close previous subheading list if necessary
                if (!$prev_was_heading) {
                    $pagequery .= '</ul>' . DOKU_LF;
                }
                if ($opt['nstitle'] && !empty($display)) {
                    $heading = $display;
                }
                if ($opt['proper'] === 'header' || $opt['proper'] === 'both') {
                    $heading = $this->proper($heading);
                }
                if (!empty($id)) {
                    $heading = $this->htmlWikilink($id, $heading, '', $opt, false, true);
                }
                $pagequery        .= '<h' . $level . ' style="' . $indent_style . '">' . $heading
                    . '</h' . $level . '>' . DOKU_LF;
                $prev_was_heading = true;
                $cont_level       = $level + 1;
                // ...or page link(s)
            } else {
                // open a new sub list if necessary
                if ($prev_was_heading || $is_first) {
                    $pagequery .= '<ul style="' . $indent_style . $list_style . '">';
                }
                // deal with normal page links
                if ($opt['proper'] === 'name' || $opt['proper'] === 'both') {
                    $display = $this->proper($display);
                }
                $link             = $this->htmlWikilink($id, $display, $abstract, $opt);
                $pagequery        .= $link;
                $prev_was_heading = false;
            }
            $is_first = false;
        }

        // and put it all together for display
        $render = '';
        $render .= '<div class="pagequery ' . $outer_border . '" id="' . $top_id . '" style="'
            . $fontsize . '">' . DOKU_LF;
        $render .= $show_count . $show_jump . $label . DOKU_LF;
        $render .= '<div class="inner ' . $inner_border . '">' . DOKU_LF;
        $render .= $pagequery . DOKU_LF;
        $render .= '</ul>' . DOKU_LF;
        $render .= '</div></div>' . DOKU_LF;
        return $render;
    }

    /**
     * Resolve a given namespace to an absolute one
     *
     * @param string $namespace
     * @return string   the resolved absolute namespace
     */
    private function resolveNamespace(string $namespace): string
    {
        global $INFO;

        $namespace .= ':dummypagename';
        $resolver = new PageResolver($INFO['id']);

        return getNS($resolver->resolveId($namespace));
    }
}
