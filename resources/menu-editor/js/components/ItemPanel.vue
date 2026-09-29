<template>
  <div class="me-item-panel">
    <ul v-if="errors.length" class="me-errors">
      <li v-for="error in errors" :key="error">{{ error }}</li>
    </ul>

    <div class="mb-3">
      <label class="form-label">{{ labels.label }}</label>
      <div v-if="languages.length > 1" class="me-tabs">
        <button v-for="lang in languages" :key="lang" type="button" class="me-tab" :class="{ 'me-tab--active': lang === activeLanguage }" @click="activeLanguage = lang">
          {{ lang.toUpperCase() }}
        </button>
      </div>
      <input v-if="activeLanguage === defaultLanguage" v-model="node.label" type="text" maxlength="255" class="form-control" :placeholder="node.fallbackLabel || ''">
      <input v-else v-model="node.i18n[activeLanguage]" type="text" maxlength="255" class="form-control" :placeholder="node.label || node.fallbackLabel || ''">
    </div>

    <div class="mb-3">
      <label class="form-label">{{ labels.target }}</label>
      <div class="me-radios">
        <label><input v-model="node.target_type" type="radio" value="content"> {{ labels.content }}</label>
        <label><input v-model="node.target_type" type="radio" value="url"> {{ labels.url }}</label>
        <label><input v-model="node.target_type" type="radio" value="none"> {{ labels.none }}</label>
      </div>
    </div>

    <TargetPicker v-if="node.target_type === 'content'" :node="node" :types="types" :search-url="searchUrl" :labels="labels" />

    <div v-if="node.target_type === 'url'" class="mb-3">
      <input v-model.trim="node.target_url" type="text" class="form-control" placeholder="https://… / /de/… / mailto:…" @blur="suggestNewWindow">
    </div>

    <div v-if="node.target_type !== 'none'" class="form-check">
      <input :id="'nw-' + node.key" v-model="node.new_window" type="checkbox" class="form-check-input">
      <label :for="'nw-' + node.key" class="form-check-label">{{ labels.newWindow }}</label>
    </div>
    <div class="form-check">
      <input :id="'on-' + node.key" type="checkbox" class="form-check-input" :checked="node.state === 2" @change="node.state = $event.target.checked ? 2 : 0">
      <label :for="'on-' + node.key" class="form-check-label">{{ labels.online }}</label>
    </div>
  </div>
</template>

<script>
import TargetPicker from './TargetPicker.vue';
import { isExternalUrl } from '../tree';

export default {
  name: 'ItemPanel',
  components: { TargetPicker },
  props: {
    node: Object,
    languages: Array,
    defaultLanguage: String,
    types: Array,
    searchUrl: String,
    errors: Array,
    labels: Object
  },
  data() {
    return { activeLanguage: this.defaultLanguage };
  },
  watch: {
    'node.key'() {
      this.activeLanguage = this.defaultLanguage;
    }
  },
  methods: {
    suggestNewWindow() {
      if (!this.node.uuid && isExternalUrl(this.node.target_url)) {
        this.node.new_window = true;
      }
    }
  }
};
</script>

<style>
.me-errors { color: #c62828; padding-left: 1.25rem; }
.me-tabs { display: flex; gap: .25rem; margin-bottom: .25rem; }
.me-tab { border: 1px solid #ddd; background: #f7f7f7; padding: .1rem .5rem; border-radius: .25rem; }
.me-tab--active { background: #795c41; color: #fff; border-color: #795c41; }
.me-radios { display: flex; gap: 1rem; flex-wrap: wrap; }
</style>
