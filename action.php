<?php

/**
 *
 * @phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
 */
class action_plugin_pagequery extends DokuWiki_Action_Plugin
{

    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, 'purgecache');
    }

    /**
     * Check for pages changes and eventually purge cache.
     *
     * @param Doku_Event $event
     * @param mixed      $param not defined
     * @author Samuele Tognini <samuele@samuele.netsons.org>
     *
     */
    public function purgecache(Doku_Event $event, $param)
    {
        global $ID;
        global $conf;
        /** @var cache_parser $cache */
        $cache = $event->data;

        if (!isset($cache->page)) {
            return;
        }
        //purge only xhtml cache
        if ($cache->mode !== "xhtml") {
            return;
        }
        //Check if it is an pagequery page
        if (!p_get_metadata($ID, 'pagequery')) {
            return;
        }
        $aclcache = $this->getConf('aclcache');
        if ($conf['useacl']) {
            $newkey = false;
            if ($aclcache === 'user') {
                //Cache per user
                if ($_SERVER['REMOTE_USER']) {
                    $newkey = $_SERVER['REMOTE_USER'];
                }
            } else {
                if ($aclcache === 'groups') {
                    //Cache per groups
                    global $INFO;
                    if ($INFO['userinfo']['grps']) {
                        $newkey = implode('#', $INFO['userinfo']['grps']);
                    }
                }
            }
            if ($newkey) {
                $cache->key   .= "#" . $newkey;
                $cache->cache = getCacheName($cache->key, $cache->ext);
            }
        }
        //Check if a page is more recent than purgefile.
        if (@filemtime($cache->cache) < @filemtime($conf['cachedir'] . '/purgefile')) {
            $event->preventDefault();
            $event->stopPropagation();
            $event->result = false;
        }
    }
}

