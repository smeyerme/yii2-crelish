<template>
  <div class="me">
    <div class="me-toolbar">
      <button type="button" class="c-button" @click="addItem" :disabled="!loaded">
        <i class="fa-sharp fa-regular fa-plus"></i> {{ labels.addItem }}
      </button>
      <button type="button" class="c-button c-button--brand" @click="save" :disabled="!loaded || saving">
        {{ saving ? labels.saving : labels.save }}
      </button>
      <span v-if="dirty" class="me-hint">{{ labels.unsaved }}</span>
      <span v-if="message" class="me-message" :class="'me-message--' + message.kind">
        {{ message.text }}
        <button v-if="message.reload" type="button" class="c-button" @click="reload">{{ labels.reload }}</button>
      </span>
    </div>

    <div class="me-body">
      <div class="me-tree-wrap">
        <p v-if="loaded && items.length === 0" class="me-empty">{{ labels.empty }}</p>
        <TreeList
          :list="items"
          :depth="1"
          :max-depth="menu.max_depth"
          :selected-key="selectedKey"
          :errors="errors"
          :labels="labels"
          @select="selectedKey = $event"
          @remove="remove"
          @refused="flash('error', labels.tooDeep)"
        />
      </div>

      <div class="me-panel">
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
        />
        <p v-else class="me-empty">{{ labels.selectItem }}</p>
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
      ignoreNextChange: false
    };
  },
  computed: {
    selected() {
      return this.selectedKey ? findNode(this.items, this.selectedKey) : null;
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
      }
    }
  },
  async mounted() {
    window.addEventListener('beforeunload', this.guard);
    const [tree, types] = await Promise.all([this.getJson(this.treeUrl), this.getJson(this.typesUrl)]);
    this.types = types || [];
    this.apply(tree);
  },
  beforeUnmount() {
    window.removeEventListener('beforeunload', this.guard);
  },
  methods: {
    async getJson(url) {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      return response.ok ? response.json() : null;
    },
    apply(tree) {
      if (!tree) {
        this.flash('error', this.labels.failed);
        return;
      }
      const keepSelected = this.selectedKey;
      this.menu = tree.menu;
      this.languages = tree.languages;
      this.defaultLanguage = tree.defaultLanguage;
      this.ignoreNextChange = true;
      this.items = nest(tree.items);
      this.selectedKey = keepSelected && findNode(this.items, keepSelected) ? keepSelected : null;
      this.dirty = false;
      this.loaded = true;
    },
    async reload() {
      this.message = null;
      this.errors = {};
      this.apply(await this.getJson(this.treeUrl));
    },
    addItem() {
      const clientId = newClientId();
      const firstType = this.types.length ? this.types[0].ctype : 'page';
      this.items.push({
        key: clientId, uuid: null, clientId, label: '', i18n: {},
        target_type: 'content', target_ctype: firstType, target_uuid: null, target_url: '',
        new_window: false, state: 2, children: [],
        targetTitle: null, targetAvailable: true, fallbackLabel: null
      });
      this.selectedKey = clientId;
    },
    remove(key) {
      removeNode(this.items, key);
      if (this.selectedKey && !findNode(this.items, this.selectedKey)) {
        this.selectedKey = null;
      }
    },
    async save() {
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
          this.apply(body);
          this.flash('success', this.labels.saved);
        } else if (response.status === 409) {
          this.message = { kind: 'error', text: this.labels.conflict, reload: true };
        } else if (response.status === 422) {
          this.errors = body.errors || {};
          const firstKey = Object.keys(this.errors).find((k) => findNode(this.items, k));
          if (firstKey) this.selectedKey = firstKey;
          this.flash('error', this.labels.invalid);
        } else {
          this.flash('error', body.error || this.labels.failed);
        }
      } catch (e) {
        this.flash('error', this.labels.failed);
      } finally {
        this.saving = false;
      }
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
.me-toolbar { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin-bottom: 1rem; }
.me-hint { color: #8a6d3b; }
.me-message--success { color: #2e7d32; }
.me-message--error { color: #c62828; }
.me-body { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 2fr); gap: 1.5rem; align-items: start; }
@media (max-width: 900px) { .me-body { grid-template-columns: 1fr; } }
.me-panel { border: 1px solid #ddd; border-radius: .25rem; padding: 1rem; position: sticky; top: 1rem; }
.me-empty { color: #777; }
</style>
