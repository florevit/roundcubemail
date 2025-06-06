<?php

/*
 +-------------------------------------------------------------------------+
 | User Interface for the Enigma Plugin                                    |
 |                                                                         |
 | Copyright (C) The Roundcube Dev Team                                    |
 |                                                                         |
 | Licensed under the GNU General Public License version 3 or              |
 | any later version with exceptions for skins & plugins.                  |
 | See the README file for a full license statement.                       |
 +-------------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                              |
 +-------------------------------------------------------------------------+
*/

class enigma_ui
{
    private $rc;
    private $enigma;
    private $css_loaded;
    private $js_loaded;
    private $data;
    private $keys_parts = [];
    private $keys_bodies = [];

    /**
     * Object constructor
     *
     * @param enigma $enigma_plugin The plugin instance
     */
    public function __construct($enigma_plugin)
    {
        $this->enigma = $enigma_plugin;
        $this->rc = $enigma_plugin->rc;
    }

    /**
     * UI initialization and requests handlers.
     */
    public function init()
    {
        $this->add_js();

        $action = rcube_utils::get_input_string('_a', rcube_utils::INPUT_GPC);

        if ($this->rc->action == 'plugin.enigmakeys') {
            switch ($action) {
                case 'delete':
                    $this->key_delete();
                    break;
                    /*
                case 'edit':
                    $this->key_edit();
                    break;
                    */
                case 'import':
                    $this->key_import();
                    break;
                case 'import-search':
                    $this->key_import_search();
                    break;
                case 'export':
                    $this->key_export();
                    break;
                case 'generate':
                    $this->key_generate();
                    break;
                case 'create':
                    $this->key_create();
                    break;
                case 'search':
                case 'list':
                    $this->key_list();
                    break;
                case 'info':
                    $this->key_info();
                    break;
            }

            $this->rc->output->add_handlers([
                'keyslist' => [$this, 'tpl_keys_list'],
                'countdisplay' => [$this, 'tpl_keys_rowcount'],
                'searchform' => [$this->rc->output, 'search_form'],
            ]);

            $this->rc->output->set_pagetitle($this->enigma->gettext('enigmakeys'));
            $this->rc->output->send('enigma.keys');
        }
        /*
        // Preferences UI
        else if ($this->rc->action == 'plugin.enigmacerts') {
            $this->rc->output->add_handlers([
                    'keyslist'     => [$this, 'tpl_certs_list'],
                    'keyframe'     => [$this, 'tpl_cert_frame'],
                    'countdisplay' => [$this, 'tpl_certs_rowcount'],
                    'searchform'   => [$this->rc->output, 'search_form'],
            ]);

            $this->rc->output->set_pagetitle($this->enigma->gettext('enigmacerts'));
            $this->rc->output->send('enigma.certs');
        }
        */
        // Message composing UI
        elseif ($this->rc->action == 'compose') {
            $this->compose_ui();
        }
    }

    /**
     * Adds CSS style file to the page header.
     */
    public function add_css()
    {
        if ($this->css_loaded) {
            return;
        }

        $skin_path = $this->enigma->local_skin_path();
        $this->enigma->include_stylesheet("{$skin_path}/enigma.css");
        $this->css_loaded = true;
    }

    /**
     * Adds javascript file to the page header.
     */
    public function add_js()
    {
        if ($this->js_loaded) {
            return;
        }

        $this->enigma->include_script('enigma.js');

        $this->rc->output->set_env('keyservers', $this->rc->config->keyservers());

        $this->js_loaded = true;
    }

    /**
     * Initializes key password prompt
     *
     * @param enigma_error $status Error object with key info
     * @param array        $params Optional prompt parameters
     */
    public function password_prompt($status, $params = [])
    {
        $data = array_merge($status->getData('missing') ?: [], $status->getData('bad') ?: []);

        // A message can be encrypted with multiple private keys,
        // find the one that exists in the keyring
        foreach ($data as $keyid => $username) {
            $key = $this->enigma->engine->get_key($keyid);
            if ($key && $key->is_private()) {
                if ($key->name && strpos($username, $keyid) !== false) {
                    $data[$keyid] = $key->name;
                }

                break;
            }
        }

        if (isset($keyid)) {
            $data = [
                'keyid' => $keyid,
                'user' => $data[$keyid] ?? null,
            ];
        } else {
            $data = [];
        }

        if (!empty($params)) {
            $data = array_merge($params, $data);
        }

        if (preg_match('/^(send|plugin.enigmaimport|plugin.enigmakeys)$/', $this->rc->action)) {
            $this->rc->output->command('enigma_password_request', $data);
        } else {
            $this->rc->output->set_env('enigma_password_request', $data);
        }

        // add some labels to client
        $this->rc->output->add_label('enigma.enterkeypasstitle', 'enigma.enterkeypass',
            'save', 'cancel');

        $this->add_css();
        $this->add_js();
    }

    /**
     * Template object for list of keys.
     *
     * @param array $attrib Object attributes
     *
     * @return string HTML content
     */
    public function tpl_keys_list($attrib)
    {
        // add id to message list table if not specified
        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmenigmakeyslist';
        }

        // define list of cols to be displayed
        $a_show_cols = ['name'];

        // create XHTML table
        $out = rcmail_action::table_output($attrib, [], $a_show_cols, 'id');

        // set client env
        $this->rc->output->add_gui_object('keyslist', $attrib['id']);
        $this->rc->output->include_script('list.js');

        // add some labels to client
        $this->rc->output->add_label('enigma.keyremoveconfirm', 'enigma.keyremoving',
            'enigma.keyexportprompt', 'enigma.withprivkeys', 'enigma.onlypubkeys',
            'enigma.exportkeys', 'enigma.importkeys', 'enigma.keyimportsearchlabel',
            'import', 'search'
        );

        return $out;
    }

    /**
     * Key listing (and searching) request handler
     */
    private function key_list()
    {
        $this->enigma->load_engine();

        $pagesize = $this->rc->config->get('pagesize', 100);
        $page = max(intval(rcube_utils::get_input_string('_p', rcube_utils::INPUT_GPC)), 1);
        $search = rcube_utils::get_input_string('_q', rcube_utils::INPUT_GPC);

        // Get the list
        $list = $this->enigma->engine->list_keys($search);
        $size = 0;
        $listsize = 0;

        if (!is_array($list)) {
            $this->rc->output->show_message('enigma.keylisterror', 'error');
        } elseif (empty($list)) {
            $this->rc->output->show_message('enigma.nokeysfound', 'notice');
        } else {
            // Save the size
            $listsize = count($list);

            // Sort the list by key (user) name
            usort($list, ['enigma_key', 'cmp']);

            // Slice current page
            $list = array_slice($list, ($page - 1) * $pagesize, $pagesize);
            $size = count($list);

            // Add rows
            foreach ($list as $key) {
                $this->rc->output->command('enigma_add_list_row', [
                    'name' => rcube::Q($key->name),
                    'id' => $key->id,
                    'flags' => $key->is_private() ? 'p' : '',
                ]);
            }
        }

        $this->rc->output->set_env('rowcount', $size);
        $this->rc->output->set_env('search_request', $search);
        $this->rc->output->set_env('pagecount', ceil($listsize / $pagesize));
        $this->rc->output->set_env('current_page', $page);
        $this->rc->output->command('set_rowcount', $this->get_rowcount_text($listsize, $size, $page));

        $this->rc->output->send();
    }

    /**
     * Template object for list records counter.
     *
     * @param array $attrib Object attributes
     *
     * @return string HTML output
     */
    public function tpl_keys_rowcount($attrib)
    {
        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmcountdisplay';
        }

        $this->rc->output->add_gui_object('countdisplay', $attrib['id']);

        return html::span($attrib, $this->get_rowcount_text());
    }

    /**
     * Returns text representation of list records counter
     */
    private function get_rowcount_text($all = 0, $curr_count = 0, $page = 1)
    {
        if (!$curr_count) {
            $out = $this->enigma->gettext('nokeysfound');
        } else {
            $pagesize = $this->rc->config->get('pagesize', 100);
            $first = ($page - 1) * $pagesize;

            $out = $this->enigma->gettext([
                'name' => 'keysfromto',
                'vars' => ['from' => $first + 1, 'to' => $first + $curr_count, 'count' => $all],
            ]);
        }

        return $out;
    }

    /**
     * Key information page handler
     */
    private function key_info()
    {
        $this->enigma->load_engine();

        $id = rcube_utils::get_input_string('_id', rcube_utils::INPUT_GET);
        $res = $this->enigma->engine->get_key($id);

        if ($res instanceof enigma_key) {
            $this->data = $res;
        } else { // error
            $this->rc->output->show_message('enigma.keyopenerror', 'error');
            $this->rc->output->command('parent.enigma_loadframe');
            $this->rc->output->send('iframe');
        }

        $this->rc->output->add_handlers([
            'keyname' => [$this, 'tpl_key_name'],
            'keydata' => [$this, 'tpl_key_data'],
        ]);

        $this->rc->output->set_pagetitle($this->enigma->gettext('keyinfo'));
        $this->rc->output->send('enigma.keyinfo');
    }

    /**
     * Template object for key name
     *
     * @param array $attrib Object attributes
     *
     * @return string HTML output
     */
    public function tpl_key_name($attrib)
    {
        return rcube::Q($this->data->name);
    }

    /**
     * Template object for key information page content
     *
     * @param array $attrib Object attributes
     *
     * @return string HTML output
     */
    public function tpl_key_data($attrib)
    {
        $out = '';
        $table = new html_table(['cols' => 2]);

        // Key user ID
        $table->add('title', html::label(null, $this->enigma->gettext('keyuserid')));
        $table->add(null, rcube::Q($this->data->name));

        // Key ID
        $table->add('title', html::label(null, $this->enigma->gettext('keyid')));
        $table->add(null, $this->data->subkeys[0]->get_short_id());

        // Key type
        $keytype = $this->data->get_type();
        $type = null;
        if ($keytype == enigma_key::TYPE_KEYPAIR) {
            $type = $this->enigma->gettext('typekeypair');
        } elseif ($keytype == enigma_key::TYPE_PUBLIC) {
            $type = $this->enigma->gettext('typepublickey');
        }

        $table->add('title', html::label(null, $this->enigma->gettext('keytype')));
        $table->add(null, $type);

        // Key fingerprint
        $table->add('title', html::label(null, $this->enigma->gettext('fingerprint')));
        $table->add(null, $this->data->subkeys[0]->get_fingerprint());

        $out .= html::tag('fieldset', null,
            html::tag('legend', null, $this->enigma->gettext('basicinfo')) . $table->show($attrib)
        );

        // Subkeys
        $table = new html_table(['cols' => 5, 'id' => 'enigmasubkeytable', 'class' => 'records-table']);

        $table->add_header('id', $this->enigma->gettext('subkeyid'));
        $table->add_header('algo', $this->enigma->gettext('subkeyalgo'));
        $table->add_header('created', $this->enigma->gettext('subkeycreated'));
        $table->add_header('expires', $this->enigma->gettext('subkeyexpires'));
        $table->add_header('usage', $this->enigma->gettext('subkeyusage'));

        $usage_map = [
            enigma_key::CAN_ENCRYPT => $this->enigma->gettext('typeencrypt'),
            enigma_key::CAN_SIGN => $this->enigma->gettext('typesign'),
            enigma_key::CAN_CERTIFY => $this->enigma->gettext('typecert'),
            enigma_key::CAN_AUTHENTICATE => $this->enigma->gettext('typeauth'),
        ];

        foreach ($this->data->subkeys as $subkey) {
            $algo = $subkey->get_algorithm();
            if ($algo && $subkey->length) {
                $algo .= ' (' . $subkey->length . ')';
            }

            $usage = [];
            foreach ($usage_map as $key => $text) {
                if ($subkey->usage & $key) {
                    $usage[] = $text;
                }
            }

            $table->set_row_attribs($subkey->revoked || $subkey->is_expired() ? 'deleted' : '');
            $table->add('id', $subkey->get_short_id());
            $table->add('algo', $algo);
            $table->add('created', $subkey->get_creation_date());
            $table->add('expires', $subkey->get_expiration_date() ?: $this->enigma->gettext('expiresnever'));
            $table->add('usage', implode(',', $usage));
        }

        $out .= html::tag('fieldset', null,
            html::tag('legend', null, $this->enigma->gettext('subkeys')) . $table->show()
        );

        // Additional user IDs
        $table = new html_table(['cols' => 2, 'id' => 'enigmausertable', 'class' => 'records-table']);

        $table->add_header('id', $this->enigma->gettext('userid'));
        $table->add_header('valid', $this->enigma->gettext('uservalid'));

        foreach ($this->data->users as $user) {
            // Display domains in UTF8
            if ($email = rcube_utils::idn_to_utf8($user->email)) {
                $user->email = $email;
            }

            $username = $user->name;
            if (!empty($user->comment)) {
                $username .= ' (' . $user->comment . ')';
            }
            $username .= ' <' . $user->email . '>';

            $table->set_row_attribs($user->revoked || !$user->valid ? 'deleted' : '');
            $table->add('id', rcube::Q(trim($username)));
            $table->add('valid', $this->enigma->gettext($user->valid ? 'valid' : 'unknown'));
        }

        $out .= html::tag('fieldset', null,
            html::tag('legend', null, $this->enigma->gettext('userids')) . $table->show()
        );

        return $out;
    }

    /**
     * Key(s) export handler
     */
    private function key_export()
    {
        $keys = rcube_utils::get_input_string('_keys', rcube_utils::INPUT_POST);
        $priv = rcube_utils::get_input_string('_priv', rcube_utils::INPUT_POST);
        $engine = $this->enigma->load_engine();
        $list = $keys == '*' ? $engine->list_keys() : explode(',', $keys);

        if (is_array($list) && ($fp = fopen('php://memory', 'rw'))) {
            $filename = 'export.pgp';
            if (count($list) == 1) {
                $filename = (is_object($list[0]) ? $list[0]->id : $list[0]) . '.pgp';
            }

            $status = null;
            foreach ($list as $key) {
                $keyid = is_object($key) ? $key->id : $key;
                $status = $engine->export_key($keyid, $fp, (bool) $priv);

                if ($status instanceof enigma_error) {
                    $code = $status->getCode();

                    if ($code == enigma_error::BADPASS) {
                        $this->password_prompt($status, [
                            'input_keys' => $keys,
                            'input_priv' => 1,
                            'input_task' => 'settings',
                            'input_action' => 'plugin.enigmakeys',
                            'input_a' => 'export',
                            'action' => '?',
                            'iframe' => true,
                            'nolock' => true,
                        ]);
                        fclose($fp);
                        $this->rc->output->send('iframe');
                    }
                }
            }

            // send download headers
            header('Content-Type: application/pgp-keys');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            rewind($fp);
            while (!feof($fp)) {
                echo fread($fp, 1024 * 1024);
            }
            fclose($fp);
        }

        exit;
    }

    /**
     * Key import (page) handler
     */
    private function key_import()
    {
        // Import process
        if ($data = rcube_utils::get_input_string('_keys', rcube_utils::INPUT_POST)) {
            $this->enigma->load_engine();
            $this->enigma->engine->password_handler();

            $result = $this->enigma->engine->import_key($data);

            if (is_array($result)) {
                if (rcube_utils::get_input_value('_generated', rcube_utils::INPUT_POST)) {
                    $this->rc->output->command('enigma_key_create_success');
                    $this->rc->output->show_message('enigma.keygeneratesuccess', 'confirmation');
                } else {
                    $this->rc->output->show_message('enigma.keysimportsuccess', 'confirmation',
                        ['new' => $result['imported'], 'old' => $result['unchanged']]);

                    if ($result['imported'] && !empty($_POST['_refresh'])) {
                        $this->rc->output->command('enigma_list', 1, false);
                    }
                }
            } else {
                $this->rc->output->show_message('enigma.keysimportfailed', 'error');
            }

            $this->rc->output->send();
        } elseif (!empty($_FILES['_file']['tmp_name']) && is_uploaded_file($_FILES['_file']['tmp_name'])) {
            $this->enigma->load_engine();
            $result = $this->enigma->engine->import_key($_FILES['_file']['tmp_name'], true);

            if (is_array($result)) {
                // reload list if any keys has been added
                if ($result['imported']) {
                    $this->rc->output->command('parent.enigma_list', 1);
                }

                $this->rc->output->show_message('enigma.keysimportsuccess', 'confirmation',
                    ['new' => $result['imported'], 'old' => $result['unchanged']]);

                $this->rc->output->command('parent.enigma_import_success');
            } elseif ($result instanceof enigma_error && $result->getCode() == enigma_error::BADPASS) {
                $this->password_prompt($result);
            } else {
                $this->rc->output->show_message('enigma.keysimportfailed', 'error');
            }
            $this->rc->output->send('iframe');
        } elseif (!empty($_FILES['_file']['error'])) {
            rcmail_action::upload_error($_FILES['_file']['error']);
            $this->rc->output->send('iframe');
        }

        $this->rc->output->add_handlers([
            'importform' => [$this, 'tpl_key_import_form'],
        ]);

        $this->rc->output->send('enigma.keyimport');
    }

    /**
     * Key import-search (page) handler
     */
    private function key_import_search()
    {
        $this->rc->output->add_handlers([
            'importform' => [$this, 'tpl_key_import_form'],
        ]);

        $this->rc->output->send('enigma.keysearch');
    }

    /**
     * Template object for key import (upload) form
     *
     * @param array $attrib Object attributes
     *
     * @return string HTML output
     */
    public function tpl_key_import_form($attrib)
    {
        $attrib += ['id' => 'rcmKeyImportForm'];

        if (empty($attrib['part']) || $attrib['part'] == 'import') {
            $title = $this->enigma->gettext('keyimportlabel');
            $upload = new html_inputfield([
                'type' => 'file',
                'name' => '_file',
                'id' => 'rcmimportfile',
                'size' => 30,
                'class' => 'form-control',
            ]);

            $max_filesize = rcmail_action::upload_init();
            $upload_button = new html_button([
                'class' => 'button import',
                'onclick' => "return rcmail.command('plugin.enigma-import','',this,event)",
            ]);

            $form = html::div(null, html::p(null, rcube::Q($this->enigma->gettext('keyimporttext'), 'show'))
                . $upload->show()
                . html::div('hint', $this->rc->gettext(['id' => 'importfile', 'name' => 'maxuploadsize', 'vars' => ['size' => $max_filesize]]))
                . (empty($attrib['part']) ? html::br() . html::br() . $upload_button->show($this->rc->gettext('import')) : '')
            );

            if (empty($attrib['part'])) {
                $form = html::tag('fieldset', '', html::tag('legend', null, $title) . $form);
            } else {
                $this->rc->output->set_pagetitle($title);
            }

            $warning = $this->enigma->gettext('keystoragenotice');
            $warning = html::div(['class' => 'boxinformation mb-3', 'id' => 'key-notice'], $warning);

            $form = $warning . $form;
        }

        if (empty($attrib['part']) || $attrib['part'] == 'search') {
            $title = $this->enigma->gettext('keyimportsearchlabel');
            $search = new html_inputfield(['type' => 'text', 'name' => '_search',
                'id' => 'rcmimportsearch', 'size' => 30, 'class' => 'form-control']);

            $search_button = new html_button([
                'class' => 'button search',
                'onclick' => "return rcmail.command('plugin.enigma-import-search','',this,event)",
            ]);

            $form = html::div(null,
                rcube::Q($this->enigma->gettext('keyimportsearchtext'), 'show')
                . html::br() . html::br() . $search->show()
                . (empty($attrib['part']) ? html::br() . html::br() . $search_button->show($this->rc->gettext('search')) : '')
            );

            if (empty($attrib['part'])) {
                $form = html::tag('fieldset', '', html::tag('legend', null, $title) . $form);
            } else {
                $this->rc->output->set_pagetitle($title);
            }

            $this->rc->output->include_script('publickey.js');
        }

        $this->rc->output->add_label('selectimportfile', 'importwait', 'nopubkeyfor', 'nopubkeyforsender',
            'encryptnoattachments', 'encryptedsendialog', 'searchpubkeyservers', 'importpubkeys',
            'encryptpubkeysfound', 'search', 'close', 'import', 'keyid', 'keylength', 'keyexpired',
            'keyrevoked', 'keyimportsuccess', 'keyservererror');

        $this->rc->output->add_gui_object('importform', $attrib['id']);

        $out = $this->rc->output->form_tag([
                'action' => $this->rc->url(['action' => $this->rc->action, 'a' => 'import']),
                'method' => 'post',
                'enctype' => 'multipart/form-data',
            ] + $attrib,
            $form ?? ''
        );

        return $out;
    }

    /**
     * Server-side key pair generation handler
     */
    private function key_generate()
    {
        // Crypt_GPG does not support key generation for multiple identities
        // It is also very slow (which is problematic because it may exceed
        // request time limit) and requires entropy generator
        // That's why we use only OpenPGP.js method of key generation
        rcmail::raise_error(['code' => 404, 'message' => 'Key generation not implemented'], true, true);

        $user = rcube_utils::get_input_string('_user', rcube_utils::INPUT_POST, true);
        $pass = rcube_utils::get_input_string('_password', rcube_utils::INPUT_POST, true);
        $size = (int) rcube_utils::get_input_value('_size', rcube_utils::INPUT_POST);

        if ($size > 4096) {
            $size = 4096;
        }

        $ident = rcube_mime::decode_address_list($user, 1, false);

        if (empty($ident)) {
            $this->rc->output->show_message('enigma.keygenerateerror', 'error');
            $this->rc->output->send();
        }

        $this->enigma->load_engine();
        $result = $this->enigma->engine->generate_key([
            'user' => $ident[1]['name'],
            'email' => $ident[1]['mailto'],
            'password' => $pass,
            'size' => $size,
        ]);

        if ($result instanceof enigma_key) {
            $this->rc->output->command('enigma_key_create_success');
            $this->rc->output->show_message('enigma.keygeneratesuccess', 'confirmation');
        } else {
            $this->rc->output->show_message('enigma.keygenerateerror', 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Key generation page handler
     */
    private function key_create()
    {
        $this->enigma->include_script('openpgp.min.js');

        $this->rc->output->add_handlers([
            'keyform' => [$this, 'tpl_key_create_form'],
        ]);

        $this->rc->output->set_pagetitle($this->enigma->gettext('keygenerate'));
        $this->rc->output->send('enigma.keycreate');
    }

    /**
     * Template object for key generation form
     *
     * @param array $attrib Object attributes
     *
     * @return string HTML output
     */
    public function tpl_key_create_form($attrib)
    {
        $attrib += ['id' => 'rcmKeyCreateForm'];
        $table = new html_table(['cols' => 2]);

        // get user's identities
        $identities = $this->rc->user->list_identities(null, true);
        $checkbox = new html_checkbox(['name' => 'identity[]']);

        $plugin = $this->rc->plugins->exec_hook('enigma_user_identities', ['identities' => $identities]);
        $identities = $plugin['identities'];
        $engine = $this->enigma->load_engine();

        foreach ($identities as $idx => $ident) {
            $name = format_email_recipient($ident['email'], $ident['name']);
            $attr = ['value' => $idx, 'data-name' => $ident['name'], 'data-email' => $ident['email_ascii']];
            $identities[$idx] = html::tag('li', null, html::label(null, $checkbox->show($idx, $attr) . rcube::Q($name)));
        }

        $table->add('title', html::label('key-name', rcube::Q($this->enigma->gettext('newkeyident'))));
        $table->add(null, html::tag('ul', 'proplist', implode("\n", $identities)));

        // Key size
        $select = new html_select(['name' => 'type', 'id' => 'key-type', 'class' => 'custom-select']);
        $select->add($this->enigma->gettext('rsa2048'), 'rsa2048');
        $select->add($this->enigma->gettext('rsa4096'), 'rsa4096');

        if ($engine->is_supported(enigma_driver::SUPPORT_ECC)) {
            $select->add($this->enigma->gettext('ecckeypair'), 'ecc');
        }

        $table->add('title', html::label('key-type', rcube::Q($this->enigma->gettext('newkeytype'))));
        $table->add(null, $select->show());

        // Password and confirm password
        $table->add('title', html::label('key-pass', rcube::Q($this->enigma->gettext('newkeypass'))));
        $table->add(null, rcube_output::get_edit_field('password', '', [
                'id' => 'key-pass',
                'size' => $attrib['size'] ?? null,
                'required' => true,
                'autocomplete' => 'new-password',
                'oninput' => "this.type = this.value.length ? 'password' : 'text'",
            ], 'text')
        );

        $table->add('title', html::label('key-pass-confirm', rcube::Q($this->enigma->gettext('newkeypassconfirm'))));
        $table->add(null, rcube_output::get_edit_field('password-confirm', '', [
                'id' => 'key-pass-confirm',
                'size' => $attrib['size'] ?? null,
                'required' => true,
                'autocomplete' => 'new-password',
                'oninput' => "this.type = this.value.length ? 'password' : 'text'",
            ], 'text')
        );

        $warning = $this->enigma->gettext('keystoragenotice');
        $warning = html::div(['class' => 'boxinformation mb-3', 'id' => 'key-notice'], $warning);

        $this->rc->output->add_gui_object('keyform', $attrib['id']);
        $this->rc->output->add_label('enigma.keygenerating', 'enigma.formerror',
            'enigma.passwordsdiffer', 'enigma.keygenerateerror', 'enigma.noidentselected',
            'enigma.keygennosupport');

        return $this->rc->output->form_tag([], $warning . $table->show($attrib));
    }

    /**
     * Key deleting
     */
    private function key_delete()
    {
        $keys = rcube_utils::get_input_value('_keys', rcube_utils::INPUT_POST);
        $engine = $this->enigma->load_engine();

        foreach ((array) $keys as $key) {
            $res = $engine->delete_key($key);

            if ($res !== true) {
                $this->rc->output->show_message('enigma.keyremoveerror', 'error');
                $this->rc->output->command('enigma_list');
                $this->rc->output->send();
            }
        }

        $this->rc->output->command('enigma_list');
        $this->rc->output->show_message('enigma.keyremovesuccess', 'confirmation');
        $this->rc->output->send();
    }

    /**
     * Init compose UI (add task button and the menu)
     */
    private function compose_ui()
    {
        $this->add_css();
        $this->rc->output->add_label('enigma.sendunencrypted');

        // Elastic skin (or a skin based on it)
        if (array_key_exists('elastic', (array) $this->rc->output->skins)) {
            $this->enigma->api->add_content($this->compose_ui_options(), 'composeoptions');
        }
        // other skins
        else {
            // Options menu button
            $this->enigma->add_button([
                    'type' => 'link',
                    'command' => 'plugin.enigma',
                    'onclick' => "rcmail.command('menu-open', 'enigmamenu', event.target, event)",
                    'class' => 'button enigma',
                    'title' => 'encryptionoptions',
                    'label' => 'encryption',
                    'domain' => $this->enigma->ID,
                    'width' => 32,
                    'height' => 32,
                    'aria-owns' => 'enigmamenu',
                    'aria-haspopup' => 'true',
                    'aria-expanded' => 'false',
                ], 'toolbar'
            );

            // Options menu contents
            $this->rc->output->add_footer($this->compose_ui_options(true));
        }
    }

    /**
     * Init compose UI (add task button and the menu)
     */
    private function compose_ui_options($wrap = false)
    {
        $locks = (array) $this->rc->config->get('enigma_options_lock');
        $chbox = new html_checkbox(['value' => 1]);

        $out = html::div('form-group form-check row',
            html::label(['for' => 'enigmasignopt', 'class' => 'col-form-label col-6'],
                rcube::Q($this->enigma->gettext('signmsg'))
            )
            . html::div('form-check col-6',
                $chbox->show($this->rc->config->get('enigma_sign_all') ? 1 : 0, [
                    'name' => '_enigma_sign',
                    'id' => 'enigmasignopt',
                    'class' => 'form-check-input',
                    'disabled' => in_array('sign', $locks),
                ])
            )
        );

        $out .= html::div('form-group form-check row',
            html::label(['for' => 'enigmaencryptopt', 'class' => 'col-form-label col-6'],
                rcube::Q($this->enigma->gettext('encryptmsg'))
            )
            . html::div('form-check col-6',
                $chbox->show($this->rc->config->get('enigma_encrypt_all') ? 1 : 0, [
                    'name' => '_enigma_encrypt',
                    'id' => 'enigmaencryptopt',
                    'class' => 'form-check-input',
                    'disabled' => in_array('encrypt', $locks),
                ])
            )
        );

        $out .= html::div('form-group form-check row',
            html::label(['for' => 'enigmaattachpubkeyopt', 'class' => 'col-form-label col-6'],
                rcube::Q($this->enigma->gettext('attachpubkeymsg'))
            )
            . html::div('form-check col-6',
                $chbox->show($this->rc->config->get('enigma_attach_pubkey') ? 1 : 0, [
                    'name' => '_enigma_attachpubkey',
                    'id' => 'enigmaattachpubkeyopt',
                    'class' => 'form-check-input',
                    'disabled' => in_array('pubkey', $locks),
                ])
            )
        );

        if (!$wrap) {
            return $out;
        }

        return html::div(['id' => 'enigmamenu', 'class' => 'popupmenu'], $out);
    }

    /**
     * Handler for message_body_prefix hook.
     * Called for every displayed (content) part of the message.
     * Adds infobox about signature verification and/or decryption
     * status above the body.
     *
     * @param array $p Original parameters
     *
     * @return array Modified parameters
     */
    public function status_message($p)
    {
        // skip: not a message part
        if ($p['part'] instanceof rcube_message) {
            return $p;
        }

        // skip: message has no signed/encoded content
        if (!$this->enigma->engine) {
            return $p;
        }

        $engine = $this->enigma->engine;
        $part_id = $p['part']->mime_id;
        $messages = [];

        // Decryption status
        if (($found = $this->find_part_id($part_id, $engine->decryptions)) !== null
            && !empty($engine->decryptions[$found])
        ) {
            $status = $engine->decryptions[$found];
            $attach_scripts = true;

            // show the message only once
            unset($engine->decryptions[$found]);

            // display status info
            $attrib = ['id' => 'enigma-message'];

            if ($status instanceof enigma_error) {
                $attrib['class'] = 'boxerror enigmaerror encrypted';
                $code = $status->getCode();

                if ($code == enigma_error::KEYNOTFOUND) {
                    $msg = rcube::Q(str_replace('$keyid', enigma_key::format_id($status->getData('id')),
                        $this->enigma->gettext('decryptnokey')));
                } elseif ($code == enigma_error::BADPASS) {
                    $missing = $status->getData('missing');
                    $label = 'decrypt' . (!empty($missing) ? 'no' : 'bad') . 'pass';
                    $msg = rcube::Q($this->enigma->gettext($label));
                    $this->password_prompt($status);
                } elseif ($code == enigma_error::NOMDC) {
                    $msg = rcube::Q($this->enigma->gettext('decryptnomdc'));
                } else {
                    $msg = rcube::Q($this->enigma->gettext('decrypterror'));
                }
            } elseif ($status === enigma_engine::ENCRYPTED_PARTIALLY) {
                $attrib['class'] = 'boxwarning enigmawarning encrypted';
                $msg = rcube::Q($this->enigma->gettext('decryptpartial'));
            } else {
                $attrib['class'] = 'boxconfirmation enigmanotice encrypted';
                $msg = rcube::Q($this->enigma->gettext('decryptok'));
            }

            $attrib['msg'] = $msg;
            $messages[] = $attrib;
        }

        // Signature verification status
        if (($found = $this->find_part_id($part_id, $engine->signatures)) !== null
            && !empty($engine->signatures[$found])
        ) {
            $sig = $engine->signatures[$found];
            $attach_scripts = true;

            // show the message only once
            unset($engine->signatures[$found]);

            // display status info
            $attrib = ['id' => 'enigma-message'];

            if ($sig instanceof enigma_signature) {
                $sender = $sig->get_sender($engine, $p['message'], $part_id);

                if ($sig->valid === enigma_error::UNVERIFIED) {
                    $attrib['class'] = 'boxwarning enigmawarning signed';
                    $msg = str_replace('$sender', $sender, $this->enigma->gettext('sigunverified'));
                    $msg = str_replace('$keyid', $sig->id, $msg);
                    $msg = rcube::Q($msg);
                } elseif ($sig->valid) {
                    $attrib['class'] = ($sig->partial ? 'boxwarning enigmawarning' : 'boxconfirmation enigmanotice') . ' signed';
                    $label = 'sigvalid' . ($sig->partial ? 'partial' : '');
                    $msg = rcube::Q(str_replace('$sender', $sender, $this->enigma->gettext($label)));
                } else {
                    $attrib['class'] = 'boxwarning enigmawarning signed';
                    if ($sender) {
                        $msg = rcube::Q(str_replace('$sender', $sender, $this->enigma->gettext('siginvalid')));
                    } else {
                        $msg = rcube::Q(str_replace('$keyid', enigma_key::format_id($sig->id),
                            $this->enigma->gettext('signokey')));
                    }
                }
            } elseif ($sig->getCode() == enigma_error::KEYNOTFOUND) {
                $attrib['class'] = 'boxwarning enigmawarning signed';
                $msg = rcube::Q(str_replace('$keyid', enigma_key::format_id($sig->getData('id')),
                    $this->enigma->gettext('signokey')));
            } else {
                $attrib['class'] = 'boxwarning enigmaerror signed';
                $msg = rcube::Q($this->enigma->gettext('sigerror'));
            }

            $attrib['msg'] = $msg;
            $messages[] = $attrib;
        }

        if ($count = count($messages)) {
            if ($count == 2 && $messages[0]['class'] == $messages[1]['class']) {
                // @phpstan-ignore-next-line
                $p['prefix'] .= html::div($messages[0], $messages[0]['msg'] . ' ' . $messages[1]['msg']);
            } else {
                foreach ($messages as $msg) {
                    $p['prefix'] .= html::div($msg, $msg['msg']);
                }
            }
        }

        if (!empty($attach_scripts)) {
            // add css and js script
            $this->add_css();
            $this->add_js();
        }

        return $p;
    }

    /**
     * Handler for message_load hook.
     * Check message bodies and attachments for keys/certs.
     */
    public function message_load($p)
    {
        $engine = $this->enigma->load_engine();

        // handle keys/certs in attachments
        foreach ((array) $p['object']->attachments as $attachment) {
            if ($engine->is_keys_part($attachment)) {
                $this->keys_parts[] = $attachment->mime_id;
            }
        }

        // the same with message bodies
        foreach ((array) $p['object']->parts as $part) {
            if ($engine->is_keys_part($part)) {
                $this->keys_parts[] = $part->mime_id;
                $this->keys_bodies[] = $part->mime_id;
            }
        }

        // @TODO: inline PGP keys

        if ($this->keys_parts) {
            $this->enigma->add_texts('localization');
        }

        return $p;
    }

    /**
     * Handler for template_object_messagebody hook.
     * This callback function adds a box below the message content
     * if there is a key/cert attachment available
     */
    public function message_output($p)
    {
        foreach ($this->keys_parts as $part) {
            // remove part's body
            if (in_array($part, $this->keys_bodies)) {
                $p['content'] = '';
            }

            // add box above the message body
            $p['content'] = html::p(['class' => 'enigmaattachment boxinformation aligned-buttons'],
                html::span(null, rcube::Q($this->enigma->gettext('keyattfound')))
                . html::tag('button', [
                        'onclick' => 'return ' . rcmail_output::JS_OBJECT_NAME . ".enigma_import_attachment('" . rcube::JQ($part) . "')",
                        'title' => $this->enigma->gettext('keyattimport'),
                        'class' => 'import btn-sm',
                    ], rcube::Q($this->rc->gettext('import'))
                )
            ) . $p['content'];

            $attach_scripts = true;
        }

        if (!empty($attach_scripts)) {
            // add css and js script
            $this->add_css();
            $this->add_js();
        }

        return $p;
    }

    /**
     * Handle message_ready hook (encryption/signing/attach public key)
     */
    public function message_ready($p)
    {
        // The message might have been already encrypted by Mailvelope
        if (str_starts_with((string) $p['message']->getParam('ctype'), 'multipart/encrypted')) {
            return $p;
        }

        $savedraft = !empty($_POST['_draft']) && empty($_GET['_saveonly']);
        $sign_enable = (bool) rcube_utils::get_input_value('_enigma_sign', rcube_utils::INPUT_POST);
        $encrypt_enable = (bool) rcube_utils::get_input_value('_enigma_encrypt', rcube_utils::INPUT_POST);
        $pubkey_enable = (bool) rcube_utils::get_input_value('_enigma_attachpubkey', rcube_utils::INPUT_POST);
        $locks = (array) $this->rc->config->get('enigma_options_lock');

        if (in_array('sign', $locks)) {
            $sign_enable = (bool) $this->rc->config->get('enigma_sign_all');
        }
        if (in_array('encrypt', $locks)) {
            $encrypt_enable = (bool) $this->rc->config->get('enigma_encrypt_all');
        }
        if (in_array('pubkey', $locks)) {
            $pubkey_enable = (bool) $this->rc->config->get('enigma_attach_pubkey');
        }

        if (!$savedraft && $pubkey_enable) {
            $engine = $this->enigma->load_engine();
            $engine->attach_public_key($p['message']);
        }

        $mode = null;
        $status = null;

        if ($encrypt_enable) {
            $engine = $this->enigma->load_engine();
            $mode = !$savedraft && $sign_enable ? enigma_engine::ENCRYPT_MODE_SIGN : null;
            $status = $engine->encrypt_message($p['message'], $mode, $savedraft);
            $mode = 'encrypt';
        } elseif (!$savedraft && $sign_enable) {
            $engine = $this->enigma->load_engine();
            $status = $engine->sign_message($p['message'], enigma_engine::SIGN_MODE_MIME);
            $mode = 'sign';
        }

        if ($mode && ($status instanceof enigma_error)) {
            $code = $status->getCode();
            $vars = [];

            if ($code == enigma_error::KEYNOTFOUND) {
                if ($email = $status->getData('missing')) {
                    $vars = ['email' => $email];
                    $msg = 'enigma.' . $mode . 'nokey';
                } else {
                    $msg = 'enigma.' . ($encrypt_enable ? 'encryptnoprivkey' : 'signnokey');
                }
            } elseif ($code == enigma_error::BADPASS) {
                $this->password_prompt($status);
            } else {
                $msg = 'enigma.' . $mode . 'error';
            }

            if (!empty($msg)) {
                if (!empty($vars['email'])) {
                    $this->rc->output->command('enigma_key_not_found', [
                        'email' => $vars['email'],
                        'text' => $this->rc->gettext(['name' => $msg, 'vars' => $vars]),
                        'title' => $this->enigma->gettext('keynotfound'),
                        'button' => $this->enigma->gettext('findkey'),
                        'mode' => $mode,
                    ]);
                } else {
                    $this->rc->output->show_message($msg, 'error', $vars);
                }
            }

            $this->rc->output->send('iframe');
        }

        return $p;
    }

    /**
     * Handler for message_compose_body hook
     * Display error when the message cannot be encrypted
     * and provide a way to try again with a password.
     */
    public function message_compose($p)
    {
        $engine = $this->enigma->load_engine();

        // skip: message has no signed/encoded content
        if (!$this->enigma->engine) {
            return $p;
        }

        $engine = $this->enigma->engine;
        $locks = (array) $this->rc->config->get('enigma_options_lock');

        // Decryption status
        foreach ($engine->decryptions as $status) {
            if ($status instanceof enigma_error) {
                $code = $status->getCode();

                if ($code == enigma_error::BADPASS) {
                    $this->password_prompt($status, ['compose-init' => true]);
                    return $p;
                }

                if ($code == enigma_error::KEYNOTFOUND) {
                    $msg = rcube::Q(str_replace('$keyid', enigma_key::format_id($status->getData('id')),
                        $this->enigma->gettext('decryptnokey')));
                } else {
                    $msg = rcube::Q($this->enigma->gettext('decrypterror'));
                }
            }
        }

        if (!empty($msg)) {
            $this->rc->output->show_message($msg, 'error');
        }

        // Check sign/encrypt options for signed/encrypted drafts
        if (!in_array('encrypt', $locks)) {
            $this->rc->output->set_env('enigma_force_encrypt', !empty($engine->decryptions));
        }
        if (!in_array('sign', $locks)) {
            $this->rc->output->set_env('enigma_force_sign', !empty($engine->signatures));
        }

        return $p;
    }

    /**
     * Handler for keys/certs import request action
     */
    public function import_file()
    {
        $uid = rcube_utils::get_input_string('_uid', rcube_utils::INPUT_POST);
        $mbox = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST);
        $mime_id = rcube_utils::get_input_string('_part', rcube_utils::INPUT_POST);
        $engine = $this->enigma->load_engine();

        if ($uid && $mime_id) {
            // Note: we get the attachment body via rcube_message class
            // to support keys inside encrypted messages (#5285)
            $message = new rcube_message($uid, $mbox);

            // Check if we don't need to ask for password again
            foreach ($engine->decryptions as $status) {
                if ($status instanceof enigma_error) {
                    if ($status->getCode() == enigma_error::BADPASS) {
                        $this->password_prompt($status, [
                            'input_uid' => $uid,
                            'input_mbox' => $mbox,
                            'input_part' => $mime_id,
                            'input_task' => 'mail',
                            'input_action' => 'plugin.enigmaimport',
                            'action' => '?',
                            'iframe' => true,
                        ]);
                        $this->rc->output->send($this->rc->output->type == 'html' ? 'iframe' : null);
                        return;
                    }
                }
            }

            if ($engine->is_keys_part($message->mime_parts[$mime_id])) {
                $part = $message->get_part_body($mime_id);
            }
        }

        if (!empty($part) && is_array($result = $engine->import_key($part))) {
            $this->rc->output->show_message('enigma.keysimportsuccess', 'confirmation',
                ['new' => $result['imported'], 'old' => $result['unchanged']]);
        } else {
            $this->rc->output->show_message('enigma.keysimportfailed', 'error');
        }

        $this->rc->output->send($this->rc->output->type == 'html' ? 'iframe' : null);
    }

    /**
     * Check if the part or its parent exists in the array
     * of decryptions/signatures. Returns found ID.
     *
     * @param string $part_id
     * @param array  $data
     *
     * @return string|null
     */
    private function find_part_id($part_id, $data)
    {
        $ids = explode('.', $part_id);
        $i = 0;
        $count = count($ids);

        // @phpstan-ignore-next-line
        while ($i < $count && strlen($part = implode('.', array_slice($ids, 0, ++$i)))) {
            if (array_key_exists($part, $data)) {
                return $part;
            }
        }

        return null;
    }
}
