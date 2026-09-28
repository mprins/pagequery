<?php
/*
 * Copyright (c) 2016 Mark C. Prins <mprins@users.sf.net>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

require_once DOKU_PLUGIN . 'pagequery/PageQuery.php';

/**
 * Test for the 'contributor' column, see #38.
 *
 * @group plugin_pagequery
 * @group plugins
 */
class contributor_column_plugin_pagequery_test extends DokuWikiTest
{
    protected $pluginsEnabled = array('pagequery');

    /**
     * buildSortingArray() should not throw when a page has no recorded
     * contributors and the 'contributor' column is requested.
     */
    public function test_missing_contributor_metadata()
    {
        saveWikiText('test:contributor_bug', 'just some content', 'create page without contributors');

        $pagequery = new PageQuery(array('no_results' => '%s'));
        $opt        = array(
            'sort' => array(),
            'filter' => array('contributor' => 'someone'),
            'snippet' => array('type' => 'none', 'count' => 0),
            'casesort' => false,
            'natsort' => false,
            'spelldate' => false,
            'dformat' => '%Y',
            'display' => '',
        );

        [$sort_array] = $pagequery->buildSortingArray(array('test:contributor_bug'), $opt);
        $this->assertSame('', $sort_array[0]['contributor']);
    }
}
