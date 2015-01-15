<?php

/**
 * Repository that is used to perform CRUD-operation on list versions
 *
 * @since 3.1
 * @package Arlima
 */
class Arlima_ListVersionRepository extends Arlima_AbstractRepositoryDB {

    /**
     * @var string
     * @var string
     */
    private $last_cache_key = 'arlima_latest_articles';

    /**
     * @var bool
     */
    private $clear_list_cache = true;

    /**
     * @param Arlima_List $list
     * @param array $articles
     * @param bool $preview
     * @return int
     * @throws Exception
     */
    public function create($list, $articles, $user_id, $preview=false)
    {
        // Update possibly changed published date of articles
        foreach( $articles as $i => $article ) {
            if( !empty($article['post']) && $connected_post = get_post($article['post']) ) {
                $articles[$i]['published'] = $this->system->getPostTimeStamp($connected_post);
            }
        }

        // Call action
        $this->system->doAction('arlima_save_list', $list);

        $this->clear_list_cache = false; // cache will be cleared later on
        $list_versions = $this->loadListVersions($list);

        // Remove oldest version or last preview version
        if( !$preview && count($list_versions['published']) > 9 ) {
            $oldest = end($list_versions['published']);
            $this->delete($oldest['id']);
        }

        // Remove possible preview versions
        foreach($list_versions['preview'] as $ver ) {
            $this->delete($ver['id']); // It should only exist one preview version but we iterate here just in case
        }

        $version_id = $this->saveVersionData($list, $articles, $user_id, $preview);

        // Reset internal clearing of cache
        $this->clear_list_cache = true;

        // Remove some cache
        $this->cache->delete('arlima_versions_'.$list->getId());
        if( !$preview ) {
            // Remove the cached articles for the latest published version
            $this->cache->delete($this->last_cache_key.$list->getId());
        }

        return $version_id;
    }

    /**
     * @param Arlima_List $list
     * @param array $articles
     * @param int $user_id
     * @param int $schedule_time
     * @return int
     */
    public function createScheduledVersion($list, $articles, $user_id, $schedule_time)
    {
        $version_id = $this->saveVersionData($list, $articles, $user_id, false, $schedule_time);
        $this->cache->delete('arlima_versions_'.$list->getId());
        $this->system->scheduleEvent($schedule_time, 'arlima_publish_scheduled_list', array( $list->getId(), $version_id ));
        return $version_id;
    }


    /**
     * Change the article collection belonging to a list version
     * @param Arlima_List $list
     * @param array $articles
     * @param int $version_id
     */
    public function update($list, $articles, $version_id)
    {
        if( !$this->versionBelongsToList($list, $version_id) )
            throw new Exception('Given version_id does not belong to given list');

        // Remove old articles
        $this->system->runSQLQuery("DELETE FROM " . $this->dbTable('_article') . " WHERE ala_alv_id=".intval($version_id));
        $this->saveArticlesForVersion($list, $articles, $version_id);
    }

    /**
     * Removes all articles in a version.
     * @param int $version_id
     */
    public function clear($version_id)
    {
        $sql = sprintf("DELETE FROM ".$this->dbTable('_article')." WHERE ala_alv_id = %d", (int)$version_id);
        $this->system->runSQLQuery($sql);
        if( $this->clear_list_cache ) {
            $this->clearArticleCache($this->loadListByVersionId($version_id), $version_id);
        }
    }


    /**
     * Calls clear() internally
     * @param int $version_id
     */
    public function delete($version_id)
    {
        $version_id = (int)$version_id;
        if( $this->clear_list_cache ) {
            // get list object before version data is removed
            $list = $this->loadListByVersionId($version_id);
            $this->clear_list_cache = false;
            $this->clear($version_id); // No need to flush cache in clear();
            $this->clear_list_cache = true;
        } else {
            $this->clear($version_id);
        }

        $sql = sprintf("DELETE FROM ".$this->dbTable('_version')." WHERE alv_id = %d", $version_id);
        $this->system->runSQLQuery($sql);

        if( isset($list) ) { // clearing cache
            $this->cache->delete('arlima_versions_'.$list->getId());
            $this->clearArticleCache($list, $version_id);
        }
    }


    /**
     * @param int $id
     * @param array $data
     * @throws Exception
     */
    public function updateArticle($id, $data)
    {
        $sql = $this->system->prepare('SELECT * FROM '.$this->dbTable('_article').' WHERE ala_id=%d', array($id));
        $cols = $this->system->runSQLQuery($sql, 'ala_');
        $cols = $this->removePrefix(current($cols), 'ala_');

        if( !empty($cols) ) {

            $sql = 'UPDATE '.$this->dbTable('_article').' SET ';
            foreach($data as $col => $val) {
                if( !isset($cols[$col]) )
                    throw new Exception('Trying to update article using unknown column "'.$col.'"');

                if($col == 'options') {
                    $val = array_merge(unserialize($cols['options']), $val);
                }
                if($col == 'options' || $col == 'image')
                    $val = serialize( $val );

                $sql .= " ala_$col = '".esc_sql(stripslashes($val))."', ";
            }

            $sql = rtrim($sql, ', ') . ' WHERE ala_id = '.intval($id);

            $this->system->runSQLQuery($sql);

            // Update cache
            $list = $this->loadListByVersionId($cols['alv_id']);
            if($list !== null) {
                $this->system->doAction('arlima_save_list', $list);
                $this->clearArticleCache($list, $cols['alv_id']);
            }
        }
    }

    /**
     * Add articles and current version to given list object
     * @param Arlima_List $list
     * @param int|bool $version
     * @param bool $include_future_posts
     * @return array
     */
    public function addArticles($list, $version=false, $include_future_posts=false)
    {
        // Add version info to list if not already added
        $published_versions = $list->getPublishedVersions();
        if( empty($published_versions) ) {
            $published_versions = $this->addVersionHistory($list);
        }

        // Point out current version for the article object
        if( !$version ) {
            if( empty($published_versions) ) {
                // No published version yet exists
                $list->setArticles(array());
                return;
            } else {
                $list->setVersion($published_versions[0]);
            }
        } else {
            foreach(array_merge($list->getPublishedVersions(), $list->getScheduledVersions()) as $ver) {
                if( $ver['id'] == $version ) {
                    $list->setVersion($ver);
                }
            }
        }

        // Decide whether or not to use cache (article collection belonging to latest
        // published version not containing future posts should be cached)
        $do_use_cache = true;
        if( $include_future_posts ) {
            $do_use_cache = false;
        } elseif( $version ) {
            if( !empty($published_versions) && $published_versions[0] != $version ) {
                $do_use_cache = false;
            }
        }

        // Load articles from db if we shouldn't use cache or if cache is empty
        if( !$do_use_cache || !($articles = $this->cache->get($this->last_cache_key.$list->getId()))) {
            list($articles, $num_future_posts) = $this->queryListArticles($list->getVersionAttribute('id'), $include_future_posts);
            if( $do_use_cache ) {
                $ttl = $num_future_posts ? 60 : 0; // Can not be cached for ever if contains future posts
                $this->cache->set($this->last_cache_key.$list->getId(), $articles, $ttl);
            }
        }

        $list->setArticles($articles);
    }

    /**
     * Add articles and version of latest preview version to given list object
     * @param Arlima_List $list
     * @throws Exception
     */
    public function addPreviewArticles($list)
    {
        // Add version info to list if not already added
        $published_versions = $list->getPublishedVersions();
        if( empty($published_versions) ) {
            $this->addVersionHistory($list);
        }

        $all_versions = $this->loadListVersions($list);
        if( empty($all_versions['preview']) ) {
            throw new Exception('No preview version of list exists');
        }

        $list->setVersion($all_versions['preview'][0]);
        list($articles) = $this->queryListArticles($list->getVersionAttribute('id'), true);
        $list->setArticles($articles);
    }

    /**
     * Add version history data to list and return an array with all published versions
     * @param Arlima_List $list
     * @return array
     */
    public function addVersionHistory($list)
    {
        $all_versions = $this->loadListVersions($list);
        $list->setPublishedVersions($all_versions['published']);
        $list->setScheduledVersions($all_versions['scheduled']);
        $published_versions = $all_versions['published'];
        return $published_versions;
    }

    /**
     * @param Arlima_List $list
     */
    public function deleteListVersions($list)
    {
        $versions = $this->loadListVersions($list);
        $this->system->runSQLQuery('DELETE FROM '.$this->dbTable('_version').' WHERE alv_al_id='.intval($list->getId()));
        $this->cache->delete($this->last_cache_key.$list->getId());
        $this->cache->delete('arlima_versions_'.$list->getId());
        foreach(array_merge($versions['published'],$versions['preview'],$versions['scheduled']) as $id ) {
            $this->clear($id);
        }
    }

    /**
     * Get an array with all versions that a list has
     * @param Arlima_List|int $list
     * @return array
     */
    public function loadListVersions($list)
    {
        $list_id = is_numeric($list) ? $list : $list->getId();
        $cached = $this->cache->get('arlima_versions_'.$list_id);
        if( $cached ) {
            return $cached;
        }

        $versions = array(
            'published' => array(),
            'preview' => array(),
            'scheduled' => array()
        );

        $sql = 'SELECT * FROM '.$this->dbTable('_version').' WHERE alv_al_id='.intval($list_id).' ORDER BY alv_id DESC';
        $version_data = $this->system->runSQLQuery($sql);
        foreach($version_data as $data ) {
            $data = $this->removePrefix($data, 'alv_');
            switch( $data['status'] ) {
                case Arlima_List::STATUS_PUBLISHED:
                    $versions['published'][] = $data;
                    break;
                case Arlima_List::STATUS_PREVIEW:
                    $versions['preview'][] = $data;
                    break;
                case Arlima_List::STATUS_SCHEDULED:
                    $versions['scheduled'][] = $data;
                    break;
            }
        }

        $this->cache->set('arlima_versions_'.$list_id, $versions);
        return $versions;
    }

    /**
     * Loads an array with objects containing list id and options
     * that have teasers that are connected to the post with $post_id
     * @param int $post_id
     * @return array
     */
    public function findListsByPostId($post_id) {
        $sql = sprintf("
                select distinct(al_id), al_options
                from %s al
                inner join (
                    select max(alv_id) as latestversion, alv_al_id
                    from %s
                    where alv_status = 1
                    group by alv_al_id
                ) alv on al.al_id = alv.alv_al_id
                INNER JOIN %s ala ON ala.ala_alv_id = alv.latestversion
                WHERE ala.ala_post = %d",
                $this->dbTable(),
                $this->dbTable('_version'),
                $this->dbTable('_article'),
                (int)$post_id
        );

        $data = $this->system->runSQLQuery($sql);
        $data = $this->removePrefix($data, 'al_');
        return $data;
    }

    /**
     * Get latest article teaser created that is related to given post
     * @param $post_id
     * @return array
     */
    public function getLatestArticle($post_id)
    {
        $sql = sprintf(
            "SELECT * FROM %s WHERE ala_post=%d ORDER BY ala_id DESC LIMIT 0,1",
            $this->dbTable('_article'),
            (int)$post_id
        );

        return $this->system->runSQLQuery($sql);
    }

    /**
     * Updates publish date for all arlima articles related to given post and clears the cache
     * of the lists where they appear
     *
     * @param stdClass|WP_Post $post
     */
    public function updateArticlePublishDate($time, $post_id)
    {
        $prep_statement = $this->system->prepare(
                                'UPDATE '.$this->dbTable('_article').'
                                SET ala_published=%d
                                WHERE ala_post=%d AND ala_published != %d',
                                array(
                                    $time,
                                    (int)$post_id,
                                    $time
                                )
                            );

        $rows_affected = $this->system->runSQLQuery($prep_statement);

        // Clear list cache
        if($rows_affected > 0) {

            /* Get id of lists that has this post, could probably be done in a better way... */
            $sql = 'SELECT DISTINCT(alv_al_id)
                    FROM '.$this->dbTable('_version').'
                    WHERE alv_id IN (
                            SELECT DISTINCT(ala_alv_id)
                            FROM '.$this->dbTable('_article').'
                            WHERE ala_post=%d
                        )';

            $ids = $this->system->runSQLQuery( $this->system->prepare($sql, array((int)$post_id)) );
            foreach($ids as $id) {
                $cache_id = $this->last_cache_key.$id->alv_al_id;
                $this->cache->delete($cache_id);
            }
        }
    }

    /**
     * @param Arlima_List $list
     * @param int $version_id
     * @return bool
     */
    public function versionBelongsToList($list, $version_id)
    {
        $versions = $this->loadListVersions($list);
        $all_versions = array_merge($versions['published'], $versions['preview'], $versions['scheduled']);
        foreach($all_versions  as $ver ) {
            if( $ver['id'] == $version_id ) {
                return true;
            }
        }

        return false;
    }



    /* * * * * * * Static utility methods * * * * * * * * * * * * */



    /**
     * The article data is in fact created with javascript in front-end so you can't
     * see this function as the sole creator of article objects. For that reason it might be
     * good to take look at this function once in a while, making sure it generates a similar object
     * as generated with javascript in front-end.
     *
     * @static
     * @param array $override[optional=array()]
     * @return array
     */
    public static function createArticle($override=array())
    {
        $article_options = array(
            'preTitle' => '',
            'streamerColor' => '',
            'streamerContent' => '',
            'streamerImage' => '',
            'streamerType' => '',
            'template' => '',
            'format' => '',
            'sectionDivider' => '',
            'overridingURL' => '',
            'target' => '',
            'floating' => false
        );

        $data = array(
            'children' => array(),
            'id' => 0,
            'image' => array(),
            'options' => $article_options,
            'post' => 0,
            'status' => 1,
            'content' => '',
            'title' => 'Unknown',
            'size' => 24,
            'created' => 0,
            'published' => 0,
            'parent' => -1
        );

        // legacy fix...
        $override = self::legacyFix($override);

        foreach($override as $key => $val) {
            if($key == 'children') {
                if(!is_array($val))
                    $val = array();
                foreach($val as $sub_art_key => $sub_art_val)
                    $val[$sub_art_key] = self::createArticle($sub_art_val);
            }
            elseif($key == 'options') {
                if(is_array($val)) {
                    foreach($val as $opt_key => $opt_val) {
                        $article_options[$opt_key] = $opt_val;
                    }
                }
                $val = $article_options;
            }

            $data[$key] = $val;
        }

        //return new Arlima_Article($data);
        return $data;
    }



    /* * * * * * * implementation of abstract functions * * * * * * */



    /**
     * @return void
     */
    function createDatabaseTables()
    {
        $table_name = $this->dbTable('_version');

        $sql = "CREATE TABLE " . $table_name . " (
        alv_id bigint(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        alv_created bigint(11) DEFAULT '0' NOT NULL,
        alv_scheduled bigint(11) DEFAULT '0' NOT NULL,
        alv_al_id bigint(11) NOT NULL,
        alv_status tinyint(1) DEFAULT '1' NOT NULL,
        alv_user_id bigint(11) NOT NULL,
        UNIQUE KEY id (alv_id),
        KEY created (alv_created),
        KEY alid (alv_al_id),
        KEY alid_created (alv_al_id, alv_created)
        );";

        $this->system->dbDelta($sql);

        $table_name = $this->dbTable('_article');

        $sql = "CREATE TABLE " . $table_name . " (
        ala_id bigint(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ala_created bigint(11) DEFAULT '0' NOT NULL,
        ala_published bigint(11) DEFAULT '0' NOT NULL,
        ala_alv_id bigint(11) NOT NULL,
        ala_post bigint(11) DEFAULT '-1' NOT NULL,
        ala_title varchar(255),
        ala_content text,
        ala_sort bigint(11) DEFAULT '100' NOT NULL,
        ala_size tinyint(2) DEFAULT '24' NOT NULL,
        ala_options text,
        ala_image text,
        ala_parent bigint(11) DEFAULT '-1' NOT NULL,
        UNIQUE KEY id (ala_id),
        KEY created (ala_created),
        KEY alvid (ala_alv_id),
        KEY alvid_created (ala_alv_id, ala_created),
        KEY alvid_sort (ala_alv_id, ala_sort),
        KEY alvid_sort_created (ala_alv_id, ala_sort, ala_created),
        KEY postid (ala_post),
        KEY postpublishdate (ala_published)
        );";

        $this->system->dbDelta($sql);
    }

    /**
     * @return array
     */
    function getDatabaseTables()
    {
        return array($this->dbTable('_version'), $this->dbTable('_article'));
    }

    /**
     * @param float $currently_installed_version
     */
    function updateDatabaseTables($currently_installed_version)
    {
        $article_tbl = $this->dbTable('_article');
        $version_tbl = $this->dbTable('_version');
        
        if($currently_installed_version < 3.0) {

            $row = $this->system->runSQLQuery('SELECT * FROM '.$article_tbl.' LIMIT 0,1');

            if( empty($row) || isset($row[0]->ala_image_options) ) {
                $this->system->runSQLQuery('ALTER TABLE '.$article_tbl.' CHANGE ala_image ala_image_depr varchar(250)');
                $this->system->runSQLQuery('ALTER TABLE '.$article_tbl.' CHANGE ala_publish_date ala_published bigint(11)');
                $this->system->runSQLQuery('ALTER TABLE '.$article_tbl.' CHANGE ala_post_id ala_post bigint(11)');
                $this->system->runSQLQuery('ALTER TABLE '.$article_tbl.' CHANGE ala_title_fontsize ala_size tinyint(2)');
                $this->system->runSQLQuery('ALTER TABLE '.$article_tbl.' CHANGE ala_image_options ala_image text');
                $this->system->runSQLQuery('ALTER TABLE '.$article_tbl.' CHANGE ala_text ala_content text');
                $this->system->flushCaches();
            }
        }
        elseif($currently_installed_version < 3.1) {
            $this->system->runSQLQuery('ALTER TABLE '.$version_tbl.' ADD alv_scheduled bigint(11) NOT NULL DEFAULT 0');
        }
    }



    /* * * * * * * Helper functions * * * * * */


    /**
     * @param $version
     * @param $include_future_posts
     * @return array
     */
    private function queryListArticles($version, $include_future_posts)
    {
        $sql = "SELECT ala_id, ala_created, ala_published, ala_post, ala_title, ala_content,
                ala_size, ala_options, ala_image, ala_parent, ala_sort
                FROM " . $this->dbTable('_article') . " %s ORDER BY ala_parent, ala_sort";

        $where = '';
        if($version)
            $where .= ' WHERE ala_alv_id = '.intval($version);

        $articles = array();
        foreach($this->system->runSQLQuery(sprintf($sql, $where) ) as $row) {

            if( !empty($row->ala_options) ) { // once upon a time this variable could be an empty string
                $row->ala_options = unserialize( $row->ala_options );
            } else {
                $row->ala_options = array();
            }

            if( !empty($row->ala_image) ) {
                $row->ala_image = unserialize( $row->ala_image );
            } else {
                $row->ala_image = array();
            }

            $row->children = array();
            $article = self::legacyFix($this->removePrefix($row, 'ala_'));

            if( $row->ala_parent == -1 ) {
                $articles[] = $article;
            } else {
                $articles[ $row->ala_parent ]['children'][] = $article;
            }
        }

        // Remove future posts
        $num_future_posts = 0;
        if( !$include_future_posts ) {

            foreach( $articles as $i => $article ) {
                if( !empty($article['published']) &&  $article['published'] > Arlima_Utils::timeStamp() ) {
                    unset( $articles[$i] );
                    $num_future_posts++;
                }
            }

            // Reset the numerical order of keys that might have been
            // mangled when removing future articles
            $articles = array_values( $articles );
        }

        return array($articles, $num_future_posts);
    }

    /**
     * @param Arlima_List $list
     * @param array $articles
     * @param int $user_id
     * @param bool $preview
     * @param int $schedule_date
     * @return mixed
     */
    private function saveVersionData($list, $articles, $user_id, $preview=false, $schedule_date=0)
    {
        // Create the new version
        $sql = "INSERT INTO " . $this->dbTable('_version') . "
                (alv_created, alv_al_id, alv_status, alv_user_id, alv_scheduled)
                VALUES (%d, %s, %d, %d, %d)";

        $state = Arlima_List::STATUS_PUBLISHED;
        if( $schedule_date )
            $state = Arlima_List::STATUS_SCHEDULED;
        elseif( $preview )
            $state = Arlima_List::STATUS_PREVIEW;

        $sql = $this->system->prepare($sql,
                array(
                    Arlima_Utils::timeStamp(),
                    $list->getId(),
                    $state,
                    $user_id,
                    $schedule_date
                )
            );

        $version_id = $this->system->runSQLQuery($sql);
        $this->saveArticlesForVersion($list, $articles, $version_id);

        return $version_id;
    }


    /**
     * @param $version
     * @return Arlima_List|null
     */
    private function loadListByVersionId($version)
    {
        $id = $this->system->runSQLQuery('SELECT alv_al_id FROM '.$this->dbTable('_version').' WHERE alv_id='.intval($version));
        if( $id ) {
            $list_repo = new Arlima_ListRepository($this->system, $this->cache);
            return $list_repo->load($id);
        }
        return null;
    }


    /**
     * @param int $version_id
     * @param array $article
     * @param mixed $sort,
     * @param int $parent[optional=-1]
     * @param int $offset
     */
    private function saveArticle($version_id, $article, $sort, $parent=-1, $offset)
    {
        foreach($article as $key => $val) {
            if( is_array($val) ) {
                foreach($article[$key] as $sub_key => $sub_val) {
                    $article[$key][$sub_key] = str_replace('\\', '', $sub_val);
                }
            } else {
                $article[$key] = str_replace('\\', '', $val);
            }
        }

        if( !isset($article['options']) || !is_array($article['options']) )
            $article['options'] = array();
        if( !isset($article['image']) || !is_array($article['image']) )
            $article['image'] = array();

        $options = serialize( $this->sanitizeArticleOptions($article['options']) );
        $image_options = serialize( $this->sanitizeImageData($article['image']) );

        $sql = $this->system->prepare(
            "INSERT INTO " . $this->dbTable('_article') . "
                    (ala_created, ala_published, ala_alv_id, ala_post, ala_title,
                    ala_content, ala_sort, ala_size, ala_options,
                    ala_image, ala_parent)
                    VALUES (%d, %d, %d, %d, %s, %s, %d, %d, %s, %s, %d)",
            array(
                empty($article['created']) ? Arlima_Utils::timeStamp():(int)$article['created'],
                empty($article['published']) ? Arlima_Utils::timeStamp():(int)$article['published'],
                $version_id,
                isset($article['post']) ? (int)$article['post']:0,
                $article['title'],
                $article['content'],
                (int)$sort,
                (int)$article['size'],
                $options,
                $image_options,
                (int)$parent
            )
        );

        $this->system->runSQLQuery($sql);

        if( !empty($article['children']) && is_array($article['children']) ) {
            foreach( $article['children'] as $sort => $child ) {
                $this->saveArticle($version_id, $child, $sort, $offset, false);
            }
        }
    }

    /**
     * @param $art_data
     * @return array
     */
    private static function legacyFix($art_data)
    {
        if (isset($art_data['title_fontsize'])) {
            $fix = array(
                'title_fontsize' => 'size',
                'post_id' => 'post',
                'publish_date' => 'published',
                'image_options' => 'image',
                'text' => 'content'
            );
            foreach ($fix as $old => $new) {
                if( isset($art_data[$old]) ) {
                    $art_data[$new] = $art_data[$old];
                    unset($art_data[$old]);
                }
            }

            # Fix options
            $art_data = self::legacyFixForOptions($art_data);

            # Fix streamer...
            if( !empty($art_data['options']['streamerType']) && $art_data['options']['streamerType'] == 'image' ) {
                $art_data['options']['streamerContent'] = $art_data['options']['streamerImage'];
                unset($art_data['options']['streamerImage']);
            }

            # Fix image
            if( !empty($art_data['image']) && $art_data['image']['alignment'] == 'aligncenter')
                $art_data['image']['alignment'] = 'alignleft';

        } elseif( isset($art_data['options']['pre_title']) || isset($art_data['options']['section_divider']) || isset($art_data['options']['file_include'])) {
            // Only fix options
            $art_data = self::legacyFixForOptions($art_data);
        }

        if( !empty($art_data['image']) && !empty($art_data['image']['attach_id']) ) {
            $art_data['image']['attachment'] = $art_data['image']['attach_id'];
            unset($art_data['image']['attach_id']);
            unset($art_data['image']['html']);
        }

        return $art_data;
    }

    /**
     * @param $art_data
     * @return array
     */
    private static function legacyFixForOptions($art_data)
    {
        $options = array(
            'hiderelated' => 'hideRelated',
            'pre_title' => 'preTitle',
            'overriding_url' => 'overridingURL',
            'streamer_content' => 'streamerContent',
            'streamer_color' => 'streamerColor',
            'streamer_type' => 'streamerType',
            'streamer_image' => 'streamerImage',
            'sticky_interval' => 'scheduledInterval',
            'sticky' => 'scheduled',
            'section_divider' => 'sectionDivider',
            'file_include' => 'fileInclude',
            'file_args' => 'fileArgs'
        );
        foreach($options as $old => $new) {
            if( isset($art_data['options'][$old]) ) {
                $art_data['options'][$new] = $art_data['options'][$old];
                unset($art_data['options'][$old]);
            }
        }
        return $art_data;
    }

    /**
     * @param array $img
     * @return array
     */
    private function sanitizeImageData($img)
    {
        // legacy fixes
        if( !empty($img['attach_id']) ) {
            $img['attachment'] = $img['attach_id'];
            unset($img['attach_id']);
        }
        if( isset($img['alignment']) && $img['alignment'] == 'aligncenter' ) {
            $img['alignment'] = 'alignleft';
        }
        return $img;
    }

    /**
     * Removes redundant information from options array
     * @param array $options
     * @return array
     */
    private function sanitizeArticleOptions($options)
    {
        $has_streamer = !empty($options['streamerType']);
        if( $has_streamer && $options['streamerType'] == 'extra' && $options['streamerContent'] != 'Extra') {
            $options['streamerContent'] = 'Extra';
        }

        if( !$has_streamer || (empty($options['streamerContent']) && empty($options['streamerColor'])) ) {
            unset($options['streamerType']);
            unset($options['streamerContent']);
            unset($options['streamerColor']);
        }

        if( empty($options['scheduled']) ) {
            unset($options['scheduledInterval']);
        }


        $new_opts = array();
        foreach($options as $key=>$val) {
            if( $val !== '' ) {
                $new_opts[$key] = str_replace('\\', '', $val);
            }
        }

        return $new_opts;
    }

    /**
     * @param Arlima_List $list
     * @param array $articles
     * @param int $version_id
     */
    private function saveArticlesForVersion($list, $articles, $version_id)
    {
        array_splice($articles, $list->getMaxlength());
        $count = 0;
        foreach ($articles as $sort => $article) {
            $this->saveArticle($version_id, $article, $sort, -1, $count++);
        }
    }

    /**
     * Clears article cache if given version is the latest published version
     * @param $list
     * @param $version
     */
    private function clearArticleCache($list, $version)
    {
        $list_versions = $this->loadListVersions($list);
        if (!empty($list_versions['published']) && $list_versions['published'][0] == $version)
            $this->cache->delete($this->last_cache_key . $list->getId());
    }
}