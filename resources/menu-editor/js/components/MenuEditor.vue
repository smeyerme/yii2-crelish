<template>
  <div class="me">
    <div class="me-toolbar d-flex flex-wrap align-items-center gap-2 mb-3">
      <code>chelper.menu('{{ menuKey }}')</code>
      <a class="c-button" :href="settingsUrl">
        <i class="fa-sharp fa-regular fa-gear"></i>&nbsp;{{ labels.settings }}
      </a>
      <button type="button" class="c-button" @click="addItem" :disabled="locked">
        <i class="fa-sharp fa-regular fa-plus"></i>&nbsp;{{ labels.addItem }}
      </button>
      <span v-if="saving" class="text-muted">{{ labels.saving }}</span>
      <span v-else-if="dirty" class="text-muted">{{ labels.unsaved }}</span>
      <span v-if="generalErrors.length" class="text-danger">{{ generalErrors.join(' ') }}</span>
      <span v-if="message" :class="message.kind === 'success' ? 'text-success' : 'text-danger'">
        {{ message.text }}
        <button v-if="message.reload" type="button" class="c-button" @click="reload">{{ labels.reload }}</button>
      </span>
    </div>

    <div class="row me-body">
      <div class="col-lg-7">
        <div class="card me-card">
          <div class="card-header">{{ labels.structure }}</div>
          <div class="card-body me-tree-wrap">
            <p v-if="loaded && items.length === 0" class="text-muted mb-0">{{ labels.empty }}</p>
            <TreeList
              :list="items"
              :depth="1"
              :max-depth="menu.max_depth"
              :selected-key="selectedKey"
              :errors="errors"
              :labels="labels"
              :disabled="locked"
              @select="selectedKey = $event"
              @remove="remove"
              @drag-started="refusedShown = false"
              @refused="refused"
            />
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card me-card me-panel-card">
          <div class="card-header">{{ labels.item }}</div>
          <div class="card-body">
            <ItemPanel
              v-if="selected"
              :key="selected.key"
              :node="selected"
              :languages="languages"
              :default-language="defaultLanguage"
              :types="types"
              :search-url="searchUrl"
              :errors="errors[selected.key] || []"
              :labels="labels"
              :disabled="locked"
            />
            <p v-else class="text-muted mb-0">{{ labels.selectItem }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import TreeList from './TreeList.vue';
import ItemPanel from './ItemPanel.vue';
import { nest, flatten, findNode, removeNode, newClientId } from '../tree';

export default {
  name: 'MenuEditor',
  components: { TreeList, ItemPanel },
  props: {
    menuKey: String,
    settingsUrl: String,
    treeUrl: String,
    saveUrl: String,
    typesUrl: String,
    searchUrl: String,
    csrfToken: String,
    labels: Object
  },
  data() {
    return {
      loaded: false,
      menu: { max_depth: 2, updated: 0 },
      languages: [],
      defaultLanguage: '',
      items: [],
      types: [],
      selectedKey: null,
      errors: {},
      dirty: false,
      saving: false,
      message: null,
      refusedShown: false,
      ignoreNextChange: false,
      errorSignatures: {}
    };
  },
  computed: {
    selected() {
      return this.selectedKey ? findNode(this.items, this.selectedKey) : null;
    },
    generalErrors() {
      const general = this.errors._;
      return Array.isArray(general) ? general : (general ? [String(general)] : []);
    },
    // No edits while a save is in flight: its response replaces the tree
    locked() {
      return !this.loaded || this.saving;
    }
  },
  watch: {
    items: {
      deep: true,
      handler() {
        if (this.ignoreNextChange) {
          this.ignoreNextChange = false;
          return;
        }
        this.dirty = true;
        if (this.message && this.message.kind === 'success') {
          this.message = null;
        }
        this.pruneErrors();
      }
    }
  },
  async mounted() {
    window.addEventListener('beforeunload', this.guard);
    // The header-bar Save runs $('#content-form').submit(): jQuery calls the inline onsubmit
    // property and skips the native POST when it returns false / prevents the default
    const form = document.getElementById('content-form');
    if (form) {
      form.onsubmit = (event) => {
        if (event) event.preventDefault();
        this.save();
        return false;
      };
    }
    await this.load();
  },
  beforeUnmount() {
    window.removeEventListener('beforeunload', this.guard);
    const form = document.getElementById('content-form');
    if (form) form.onsubmit = () => false;
  },
  methods: {
    async getJson(url) {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      return response.ok ? response.json() : null;
    },
    // Serialise a node's own fields (not its children) to detect edits
    signature(node) {
      const { children, ...own } = node;
      return JSON.stringify(own);
    },
    // Drop errors of nodes that were edited or removed; drop the "please fix" message when none remain
    pruneErrors() {
      const keys = Object.keys(this.errors).filter((k) => k !== '_');
      if (!keys.length) return;
      let changed = false;
      const next = { ...this.errors };
      keys.forEach((key) => {
        const node = findNode(this.items, key);
        if (!node || this.errorSignatures[key] !== this.signature(node)) {
          delete next[key];
          delete this.errorSignatures[key];
          changed = true;
        }
      });
      if (!changed) return;
      this.errors = next;
      if (!Object.keys(next).length && this.message && this.message.invalid) {
        this.message = null;
      }
    },
    apply(tree) {
      if (!tree) {
        this.message = { kind: 'error', text: this.labels.failed, reload: true };
        return;
      }
      const keepSelected = this.selectedKey;
      this.menu = tree.menu;
      this.languages = tree.languages;
      this.defaultLanguage = tree.defaultLanguage;
      this.ignoreNextChange = true;
      const nodes = nest(tree.items);
      const normalise = (list) => list.forEach((node) => {
        if (!node.i18n || Array.isArray(node.i18n) || typeof node.i18n !== 'object') node.i18n = {};
        tree.languages.forEach((lang) => {
          if (lang !== tree.defaultLanguage) node.i18n[lang] = node.i18n[lang] ?? '';
        });
        normalise(node.children);
      });
      normalise(nodes);
      this.items = nodes;
      this.selectedKey = keepSelected && findNode(this.items, keepSelected) ? keepSelected : null;
      this.dirty = false;
      this.loaded = true;
    },
    // Tree and content types together: a failure of either shows the failure message with Reload
    async load() {
      try {
        const [tree, types] = await Promise.all([this.getJson(this.treeUrl), this.getJson(this.typesUrl)]);
        if (!tree || !Array.isArray(types)) throw new Error('load');
        this.types = types;
        this.apply(tree);
      } catch (e) {
        this.message = { kind: 'error', text: this.labels.failed, reload: true };
      }
    },
    async reload() {
      this.message = null;
      this.errors = {};
      this.errorSignatures = {};
      await this.load();
    },
    addItem() {
      if (this.locked) return;
      const clientId = newClientId();
      const i18n = {};
      this.languages.forEach((lang) => {
        if (lang !== this.defaultLanguage) i18n[lang] = '';
      });
      const firstType = this.types.length ? this.types[0].ctype : 'page';
      this.items.push({
        key: clientId, uuid: null, clientId, label: '', i18n,
        target_type: 'content', target_ctype: firstType, target_uuid: null, target_url: '',
        new_window: false, state: 2, children: [],
        targetTitle: null, targetAvailable: true, fallbackLabel: null
      });
      this.selectedKey = clientId;
    },
    remove(key) {
      if (this.locked) return;
      removeNode(this.items, key);
      if (this.selectedKey && !findNode(this.items, this.selectedKey)) {
        this.selectedKey = null;
      }
    },
    async save() {
      if (this.locked) return;
      this.saving = true;
      this.message = null;
      try {
        const response = await fetch(this.saveUrl, {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.csrfToken },
          body: JSON.stringify({ updated: this.menu.updated, items: flatten(this.items) })
        });
        const body = await response.json().catch(() => ({}));

        if (response.status === 200) {
          this.errors = {};
          this.errorSignatures = {};
          this.apply(body);
          this.flash('success', this.labels.saved);
        } else if (response.status === 409) {
          this.message = { kind: 'error', text: this.labels.conflict, reload: true };
        } else if (response.status === 422) {
          this.errors = body.errors || {};
          const firstKey = Object.keys(this.errors).find((k) => findNode(this.items, k));
          if (firstKey) this.selectedKey = firstKey;
          this.errorSignatures = {};
          Object.keys(this.errors).forEach((k) => {
            const node = findNode(this.items, k);
            if (node) this.errorSignatures[k] = this.signature(node);
          });
          this.message = { kind: 'error', text: this.labels.invalid, reload: false, invalid: true };
        } else {
          this.flash('error', body.error || this.labels.failed);
        }
      } catch (e) {
        this.flash('error', this.labels.failed);
      } finally {
        this.saving = false;
      }
    },
    refused() {
      if (this.refusedShown) return;
      this.refusedShown = true;
      this.flash('error', this.labels.tooDeep);
    },
    flash(kind, text) {
      this.message = { kind, text, reload: false };
    },
    guard(event) {
      if (this.dirty) {
        event.preventDefault();
        event.returnValue = '';
      }
    }
  }
};
</script>

<style>
.me { color: var(--color-text-dark); }
.me .me-card { height: auto; }
.me .me-panel-card { position: sticky; top: 1rem; }
.me button.c-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: .5rem 1rem;
  border-radius: var(--border-radius-md);
  background-color: var(--color-bg-light);
  color: var(--color-text-dark);
  font-weight: 500;
  border: 1px solid var(--color-border);
  line-height: 1.5;
  transition: var(--transition-standard);
}
.me button.c-button:hover:not(:disabled) {
  background-color: rgba(var(--color-primary-light-rgb), .1);
  color: var(--color-primary-light);
  box-shadow: var(--shadow-sm);
}
[data-theme="dark"] .me button.c-button { color: var(--color-text-light); }
[data-theme="dark"] .me button.c-button:hover:not(:disabled) {
  background-color: rgba(var(--color-primary-light-rgb), .3);
  color: var(--color-text-light);
}
.me button.c-button:disabled { opacity: .5; cursor: not-allowed; }
</style>
