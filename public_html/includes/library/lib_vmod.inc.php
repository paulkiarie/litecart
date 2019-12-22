<?php

  class vmod {
    public static $enabled = true;                      // Bool whether or not to enable this feature
    private static $_modifications = array();           // Array of modifications to apply
    private static $_files_to_modifications = array();  // Array of modifications to apply
    private static $_checked = array();                 // Array of files that have already passed check() and
    private static $_checksums = array();               // Array of checksums for time comparison
    private static $_aliases = array();                 // Array of path aliases
    private static $_installed = array();               // Array of path aliases
    public static $time_elapsed = 0;                    // Array of path aliases

    public static function init() {

      if (!self::$enabled) return;

      $timestamp = microtime(true);

      self::$_aliases['#^admin/#'] = BACKEND_ALIAS . '/';
      self::$_aliases['#^includes/controllers/ctrl_#'] = 'includes/entities/ent_';

      $last_modified = null;

    // If no cache is requested by browser
      if (isset($_SERVER['HTTP_CACHE_CONTROL'])) {
        if (strpos(strtolower($_SERVER['HTTP_CACHE_CONTROL']), 'no-cache') !== false) $last_modified = time();
        if (strpos(strtolower($_SERVER['HTTP_CACHE_CONTROL']), 'max-age=0') !== false) $last_modified = time();

      } else {

      // Get last modification date for modifications
        $folder_last_modified = filemtime(FS_DIR_APP .'vmods/');
        if ($folder_last_modified > $last_modified) {
          $last_modified = $folder_last_modified;
        }

        foreach (glob(FS_DIR_APP .'vmods/*.xml') as $file) {
          $file_last_modified = filemtime($file);
          if ($file_last_modified > $last_modified) {
            $last_modified = $file_last_modified;
          }
        }

        //database::query(
        //"select update_time from information_schema.tables
        //where TABLE_SCHEMA = '". DB_DATABASE ."'
        //and table_name = '". DB_TABLE_PREFIX ."modifications'
        //limit 1;"
      }

    // Load installed
      $installed_file = FS_DIR_APP . 'vmods/.installed';
      if (is_file($installed_file)) {
        foreach (file($installed_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $vmod_id) {
          self::$_installed[] = $vmod_id;
        }
      }

    // Get modifications from cache
      $cache_file = FS_DIR_APP . 'cache/vmod_modifications.cache';
      if (is_file($cache_file) && filemtime($cache_file) > $last_modified) {
        if ($cache = file_get_contents($cache_file)) {
          if ($cache = json_decode($cache, true)) {
            self::$_modifications = $cache['modifications'];
            self::$_files_to_modifications = $cache['index'];
          }
        }
      }

      $checked_file = FS_DIR_APP . 'cache/vmod_checked.cache';
      if (is_file($checked_file) && filemtime($checked_file) > $last_modified) {
        foreach (file($checked_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
          list($short_file, $modified_file, $checksum) = explode(';', $line);
          self::$_checked[$short_file] = $modified_file;
          self::$_checksums[$short_file] = $checksum;
        }
      } else {
        file_put_contents($checked_file, '', LOCK_EX);
      }

    // Load modifications from disk
      if (empty(self::$_modifications)) {
        foreach (glob(FS_DIR_APP .'vmods/*.xml') as $file) {
          self::_load_file($file);
        }

      // Store modifications to cache
        $serialized = json_encode(array(
          'modifications' => self::$_modifications,
          'index' => self::$_files_to_modifications,
        //), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        ), JSON_UNESCAPED_SLASHES);

        file_put_contents($cache_file, $serialized);
      }

      self::$time_elapsed += microtime(true) - $timestamp;
    }

  // Return a modified file
    public static function check($file) {

    // Halt if there is nothing to modify
      if (!self::$enabled || empty($file) || empty(self::$_files_to_modifications)) {
        return $file;
      }

      $timestamp = microtime(true);

      if (!is_file($file)) {
        // check here if there is a modification creating the file
        self::$time_elapsed += microtime(true) - $timestamp;
        return $file;
      } else {
        $file = str_replace('\\', '/', realpath($file));
      }

      $short_file = preg_replace('#^('. preg_quote(FS_DIR_APP, '#') .')#', '', $file);
      $modified_file = FS_DIR_APP . 'cache/modifications/' . preg_replace('#[/\\\\]+#', '_', $short_file);

    // Returned already checked file
      if (!empty(self::$_checked[$short_file]) && file_exists(self::$_checked[$short_file])) {
        self::$time_elapsed += microtime(true) - $timestamp;
        return self::$_checked[$short_file];
      }

    // Add modifications to queue and calculate checksum
      $queue = array();
      $digest = array(filemtime($file));

      foreach (self::$_files_to_modifications as $pattern => $modifications) {
        if (!fnmatch($pattern, $short_file)) continue;

        foreach ($modifications as $modification) {
          $digest[] = strtotime($modification['date_modified']);
        }

        $queue[] = $modifications;
      }

      $checksum = md5(implode('', $digest));

    // Return original if nothing to modify
      if (empty($queue)) {
        if (is_file($modified_file)) unset($modified_file);
        self::$time_elapsed += microtime(true) - $timestamp;
        return self::$_checked[$short_file] = $file;
      }

    // Return modified file if checksum matches
      if (!empty(self::$_checksums[$short_file]) && self::$_checksums[$short_file] == $checksum) {
        self::$time_elapsed += microtime(true) - $timestamp;
        return self::$_checked[$short_file] = $modified_file;
      }

    // Modify file
      if (is_file($file)) {
        $original = $buffer = preg_replace('#(\r\n|\r|\n)#', PHP_EOL, file_get_contents($file));
      } else {
        $original = $buffer = null;
      }

      foreach ($queue as $modifications) {
        foreach ($modifications as $modification) {

          if (!$vmod = self::$_modifications[$modification['id']]) continue;
          if (!$operations = self::$_modifications[$modification['id']]['files'][$short_file]['operations']) continue;

          $tmp = $buffer; $i=0;
          foreach ($operations as $operation) {
            $i++;

            if (!empty($operation['ignoreif']) && preg_match($operation['ignoreif'], $tmp)) {
              continue;
            }

            $found = preg_match_all($operation['find']['pattern'], $tmp, $matches, PREG_OFFSET_CAPTURE);

            if (!$found) {
              switch ($operation['onerror']) {
                case 'warning':
                  trigger_error("Vmod \"{$vmod['title']}\" failed during operation #$i in \"{$short_file}\": Search not found" . PHP_EOL . $operation['find']['pattern'], E_USER_WARNING);
                  $modifications = $recovery;
                  continue 3;
                case 'ignore':
                default:
                  continue 2;
              }
            }

            if (!empty($operation['find']['indexes'])) {
              rsort($operation['find']['indexes']);

              foreach ($operation['find']['indexes'] as $index) {
                $index = $index - 1; // [0] is the 1st in computer language

                if ($found > $index) {
                  $tmp = substr_replace($tmp, preg_replace($operation['find']['pattern'], $operation['insert'], $matches[0][$index][0]), $matches[0][$index][1], strlen($matches[0][$index][0]));
                }
              }

            } else {
              $tmp = preg_replace($operation['find']['pattern'], $operation['insert'], $tmp, -1, $count);

              if (!$count && $operation['onerror'] != 'skip') {
                trigger_error("Vmod failed to perform insert", E_USER_ERROR);
                continue 2;
              }
            }
          }

          $buffer = $tmp;
        }
      }

    // Create cache folder for modified files if missing
      if (!is_dir(FS_DIR_APP . 'cache/modifications/')) {
        if (!mkdir(FS_DIR_APP . 'cache/modifications/', 0777)) {
          throw new \Exception('The modifications cache directory could not be created', E_USER_ERROR);
        }
      }

      if (!is_writable(FS_DIR_APP . 'cache/modifications/')) {
        throw new \Exception('The modifications cache directory is not writable', E_USER_ERROR);
      }

    // Return original if nothing was modified
      if ($buffer == $original) {
        self::$time_elapsed += microtime(true) - $timestamp;
        return self::$_checked[$short_file] = $file;
      }

    // Write modified file
      file_put_contents($modified_file, $buffer);

      self::$_checked[$short_file] = $modified_file;
      self::$_checksums[$short_file] = $checksum;
      file_put_contents(FS_DIR_APP . 'cache/vmod_checked.cache', $short_file .';'. $modified_file .';'. $checksum . PHP_EOL, FILE_APPEND | LOCK_EX);

      self::$time_elapsed += microtime(true) - $timestamp;

      return $modified_file;
    }

    public static function _load_file($file) {

      try {

        $xml = file_get_contents($file);
        $xml = preg_replace('#(\r\n|\r|\n)#', PHP_EOL, $xml);

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;

        if (!$dom->loadXml($xml)) {
          throw new \Exception(libxml_get_errors());
        }

        switch ($dom->documentElement->tagName) {

          case 'vmod': // LiteCart Modification
            $vmod = self::_parse_vmod($dom);
            break;

          case 'modification': // vQmod
            $vmod = self::_parse_vqmod($dom);
            break;

          default:
            throw new \Exception("File ($file) is not a valid vmod or vQmod");
        }

        $vmod['id'] = basename($file);
        $vmod['date_modified'] = filemtime($file);

        self::$_modifications[$vmod['id']] = $vmod;

      // Create cross reference for file patterns
        foreach (array_keys($vmod['files']) as $key) {
          $patterns = explode(',', $vmod['files'][$key]['name']);

          foreach ($patterns as $pattern) {
            $path_and_file = $vmod['files'][$key]['path'].$pattern;

          // Apply path aliases
            if (!empty(self::$_aliases)) {
              $path_and_file = preg_replace(array_keys(self::$_aliases), array_values(self::$_aliases), $path_and_file);
            }

            self::$_files_to_modifications[$path_and_file][] = array(
              'id' => $vmod['id'],
              //'index' => $vmod['files'][$key]['path'].$vmod['files'][$key]['name'],
              'date_modified' => $vmod['date_modified'],
            );
          }
        }

    // Run install for previously not installed modifications
        if (!in_array($vmod['id'], self::$_installed)) {

        // Exceute install in an isolated scope
          if (!empty($vmod['install'])) {
            (function(){
              eval(func_get_args()[0]);
            })($vmod['install']);
          }

          file_put_contents(FS_DIR_APP . 'vmods/.installed', $vmod['id'] . PHP_EOL, FILE_APPEND | LOCK_EX);
          self::$_installed[] = $vmod['id'];
        }

      } catch (\Exception $e) {
        trigger_error("Could not parse file ($file): " . $e->getMessage(), E_USER_WARNING);
      }
    }

    private static function _parse_vmod($dom) {

      if ($dom->documentElement->tagName != 'vmod') {
        throw new \Exception('File is not a valid vmod');
      }

      if (empty($dom->getElementsByTagName('title')->item(0))) {
        throw new \Exception('File is missing the title element');
      }

      $vmod = array(
        //'id' => '',
        'title' => $dom->getElementsByTagName('title')->item(0)->textContent,
        'files' => array(),
        'install' => null,
      );

      if ($dom->getElementsByTagName('install')->length > 0) {
        $vmod['install'] = $dom->getElementsByTagName('install')->item(0)->textContent;
      }


      //if ($dom->getElementsByTagName('alias')->length > 0) {
        $aliases = array();
        foreach ($dom->getElementsByTagName('alias') as $alias_node) {
          $aliases[$alias_node->getAttribute('key')] = $alias_node->getAttribute('value');
        }
      //}

      if (empty($dom->getElementsByTagName('file'))) {
        throw new \Exception('File has no defined files to modify');
      }

      foreach ($dom->getElementsByTagName('file') as $file_node) {

        $vmod_file = array(
          'path' => $file_node->getAttribute('path'),
          'name' => $file_node->getAttribute('name'),
          'operations' => array(),
        );

        foreach ($file_node->getElementsByTagName('operation') as $operation_node) {

        // On Error
          $onerror = $operation_node->getAttribute('onerror');

        // Find
          $find_node = $operation_node->getElementsByTagName('find')->item(0);
          $find = strtr($find_node->textContent, $aliases);

          if ($find_node->getAttribute('regex') == 'true') {
            $find = trim($find);

          } else {

          // Trim
            if ($find_node->getAttribute('trim') != 'false') {
              $find_node->textContent = preg_replace('#^\r?\n?#s', '', $find_node->textContent); // Trim beginning of CDATA
              $find_node->textContent = preg_replace('#\r?\n[\t ]*$#s', '', $find_node->textContent); // Trim end of CDATA
            }

          // Offset
            $offset_before = str_repeat('.*?['. addcslashes(PHP_EOL, "\r\n") .']', (int)$find_node->getAttribute('offset-before'));
            $offset_after  = str_repeat('.*?['. addcslashes(PHP_EOL, "\r\n") .']', (int)$find_node->getAttribute('offset-after')+2);

          // Whitespace
            $find = preg_split('#(\r\n|\r|\n)#', $find);
            for ($i=0; $i<count($find); $i++) {
              if ($find[$i] = trim($find[$i])) {
                $find[$i] = '(?:[ \\t]+)?' . preg_quote($find[$i], '#') . '(?:[ \\t]+)?';
              } else {
                $find[$i] = '(?:[ \\t]+)?';
              }
            }
            $find = implode(PHP_EOL . '', $find);

            $find = '#'. $offset_before . $find . $offset_after .'#';
          }

        // Indexes
          if ($indexes = $find_node->getAttribute('index')) {
            $indexes = preg_split('#, ?#', $indexes);
          }

        // Ignoreif
          if ($ignoreif_node = $operation_node->getElementsByTagName('ignoreif')->item(0)) {
            $ignoreif = strtr($ignoreif_node->textContent, $aliases);

            if ($ignoreif_node->getAttribute('regex') == 'true') {
              $ignoreif = trim($ignoreif);

            } else {

              if ($ignoreif_node->getAttribute('trim') != 'false') {
                $ignoreif = preg_replace('#^\r?\n?#s', '', $ignoreif); // Trim beginning of CDATA
                $ignoreif = preg_replace('#\r?\n[\t ]*$#s', '', $ignoreif); // Trim end of CDATA
              }

              $ignoreif = '#'. preg_quote($ignoreif, '#') .'#';
            }
          }

        // Insert
          $insert_node = $operation_node->getElementsByTagName('insert')->item(0);
          $insert = strtr($insert_node->textContent, $aliases);

          if ($insert_node->getAttribute('regex') == 'true') {
            $insert = trim($insert);

          } else {

            if ($insert_node->getAttribute('trim') != 'false') {
              $insert = preg_replace('#^\r?\n?#s', '', $insert); // Trim beginning of CDATA
              $insert = preg_replace('#\r?\n[\t ]*$#s', '', $insert); // Trim end of CDATA
            }

            switch($position = $insert_node->getAttribute('position')) {

              case 'before':
              case 'prepend':
                $insert = addcslashes($insert, '\\$').'$0';
                break;

              case 'after':
              case 'append':
                $insert = '$0'. addcslashes($insert, '\\$');
                break;

              case 'top':
                $find = '#^.*$#s';
                $indexes = '';
                $insert = addcslashes($insert, '\\$').'$0';
                break;

              case 'bottom':
                $find = '#^.*$#s';
                $indexes = '';
                $insert = '$0'.addcslashes($insert, '\\$');
                break;

              case 'replace':
                $insert = addcslashes($insert, '\\$');
                break;

              case 'all':
                $find = '#^.*$#s';
                $indexes = '';
                $insert = addcslashes($insert, '\\$');
                break;

              default:
                throw new \Exception("Unknown value \"$position\" for attribute position (replace|before|after|all)");
                continue 2;
            }
          }

        // Gather
          $vmod_file['operations'][] = array(
            'onerror' => $onerror,
            'find' => array(
              'pattern' => $find,
              'indexes' => $indexes,
            ),
            'ignoreif' => !empty($ignoreif) ? $ignoreif : null,
            'insert' => $insert,
          );
        }

        $vmod['files'][$vmod_file['path'].$vmod_file['name']] = $vmod_file;
      }

      return $vmod;
    }

    private static function _parse_vqmod($dom) {

      if ($dom->documentElement->tagName != 'modification') {
        throw new \Exception("File is not a valid vQmod");
      }

      if (empty($dom->getElementsByTagName('id')->item(0))) {
        throw new \Exception("File is missing the id element");
      }

      $mod = array(
        'title' => $dom->getElementsByTagName('id')->item(0)->textContent,
        'files' => array(),
      );

      if (empty($dom->getElementsByTagName('file'))) {
        throw new \Exception("File has no defined files to modify");
      }

      foreach ($dom->getElementsByTagName('file') as $file_node) {

        // On Error
          switch ($file_node->getAttribute('error')) {
            case 'error':
              $onerror = 'warning';
              break;

            case 'skip':
              $onerror = 'ignore';
              break;

            case 'abort':
            default:
              $onerror = 'cancel';
              break;
          }

        $mod_file = array(
          'path' => $file_node->getAttribute('path'),
          'name' => $file_node->getAttribute('name'),
          'operations' => array()
        );

        foreach ($file_node->getElementsByTagName('operation') as $operation_node) {

        // Search
          $search_node = $operation_node->getElementsByTagName('search')->item(0);
          $search = $search_node->textContent;

        // Regex
          if ($search_node->getAttribute('regex') == 'true') {
            $search = trim($search);

          } else {

          // Trim
            if ($search_node->getAttribute('trim') != 'false') {
              $search = preg_replace('#^\r?\n?#s', '', $search); // Trim beginning of CDATA
              $search = preg_replace('#\r?\n[\t ]*$#s', '', $search); // Trim end of CDATA
            }

          // Whitespace
            $search = preg_split('#(\r\n|\r|\n)#', $search);
            for ($i=0; $i<count($search); $i++) {
              if ($search[$i] = trim($search[$i])) {
                $search[$i] = '(?:[ \\t]+)?' . preg_quote($search[$i], '#') . '(?:[ \\t]+)?';
              } else {
                $search[$i] = '(?:[ \\t]+)?';
              }
            }
            $search = implode(PHP_EOL . '', $search);

          // Offset
            if ($search_node->getAttribute('offset') && in_array($search_node->getAttribute('position'), array('before', 'after', 'replace'))) {
              switch ($search_node->getAttribute('position')) {
                case 'before':
                  $offset_before = str_repeat('.*?(?:\r\n|\r|\n)', (int)$search_node->getAttribute('offset'));
                  $offset_after  = '';
                  break;
                case 'after':
                case 'replace':
                  $offset_before = '';
                  $offset_after = str_repeat('.*?(?:\r\n|\r|\n)', (int)$search_node->getAttribute('offset')+1);
                  break;
                default:
                  $offset_before = '';
                  $offset_after = '';
                  break;
              }
              $search = $offset_before . $search . $offset_after;
            }

            $search = '#'. $search .'#';
          }

        // Indexes
          if ($indexes = $search_node->getAttribute('index')) {
            $indexes = preg_split('#, ?#', $indexes);
          }

        // Ignoreif
          if ($ignoreif_node = $operation_node->getElementsByTagName('ignoreif')->item(0)) {
            $ignoreif = $ignoreif_node->textContent;

            if ($ignoreif_node->getAttribute('regex') == 'true') {
              $ignoreif = trim($ignoreif);

            } else {

              if ($ignoreif_node->getAttribute('trim') != 'false') {
                $ignoreif = preg_replace('#^\r?\n?#s', '', $ignoreif); // Trim beginning of CDATA
                $ignoreif = preg_replace('#\r?\n[\t ]*$#s', '', $ignoreif); // Trim end of CDATA
              }

              $ignoreif = '#'. preg_quote($ignoreif, '#') .'#';
            }
          }

        // Add
          $add_node = $operation_node->getElementsByTagName('add')->item(0);
          $add = $add_node->textContent;

          if ($add_node->getAttribute('regex') == 'true') {
            $add = trim($add);

          } else {

            if ($add_node->getAttribute('trim') != 'false') {
              $add = preg_replace('#^\r?\n?#s', '', $add); // Trim beginning of CDATA
              $add = preg_replace('#\r?\n[\t ]*$#s', '', $add); // Trim end of CDATA
            }

            switch($search_node->getAttribute('position')) {

              case 'before':
              case 'ibefore':
                $add = addcslashes($add, '\\$').'$0';
                break;

              case 'after':
              case 'iafter':
                $add = '$0'. addcslashes($add, '\\$');
                break;

              case 'top':
                $search = '#^.*$#s';
                $indexes = '';
                $add = addcslashes($add, '\\$').'$0';
                break;

              case 'bottom':
                $search = '#^.*$#s';
                $indexes = '';
                $add = '$0'.addcslashes($add, '\\$');
                break;

              case 'replace':
              case 'ireplace':
                $add = addcslashes($add, '\\$');
                break;

              case 'all':
                $search = '#^.*$#s';
                $indexes = '';
                $add = addcslashes($add, '\\$');
                break;

              default:
                throw new \Exception('Unknown value ('. $search_node->getAttribute('position') .') for attribute position (replace|before|after|ireplace|ibefore|iafter)');
                continue 2;
            }
          }

        // Gather
          $mod_file['operations'][] = array(
            'onerror' => $onerror,
            'find' => array(
              'pattern' => $search,
              'indexes' => $indexes,
            ),
            'ignoreif' => !empty($ignoreif) ? $ignoreif : null,
            'insert' => $add,
          );
        }

        $mod['files'][$mod_file['path'].$mod_file['name']] = $mod_file;
      }

      return $mod;
    }
  }
