<template>
  <fieldset class="me-item-panel" :disabled="disabled">
    <div v-if="errors.length" class="invalid-feedback d-block mb-3">
      <ul class="mb-0 ps-3">
        <li v-for="error in errors" :key="error">{{ error }}</li>
      </ul>
    </div>

    <div class="mb-3">
      <label class="form-label" :for="'label-' + node.key">{{ labels.label }}</label>
      <ul v-if="languages.length > 1" class="nav nav-tabs small mb-2">
        <li v-for="lang in languages" :key="lang" class="nav-item">
          <button type="button" class="nav-link py-1 px-2" :class="{ active: lang === activeLanguage }" @click="activeLanguage = lang">
            {{ lang.toUpperCase() }}
          </button>
        </li>
      </ul>
      <input v-if="activeLanguage === defaultLanguage" :id="'label-' + node.key" v-model="node.label" type="text" maxlength="255" class="form-control" :placeholder="node.fallbackLabel || ''">
      <input v-else :id="'label-' + node.key" v-model="node.i18n[activeLanguage]" type="text" maxlength="255" class="form-control" :placeholder="node.label || node.fallbackLabel || ''">
    </div>

    <div class="mb-3">
      <label class="form-label d-block">{{ labels.target }}</label>
      <div v-for="option in targetOptions" :key="option.value" class="form-check form-check-inline">
        <input :id="'tt-' + option.value + '-' + node.key" v-model="node.target_type" class="form-check-input" type="radio" :value="option.value">
        <label :for="'tt-' + option.value + '-' + node.key" class="form-check-label">{{ option.label }}</label>
      </div>
    </div>

    <TargetPicker v-if="node.target_type === 'content'" :node="node" :types="types" :search-url="searchUrl" :labels="labels" />

    <div v-if="node.target_type === 'url'" class="mb-3">
      <label class="form-label" :for="'url-' + node.key">{{ labels.url }}</label>
      <input :id="'url-' + node.key" v-model.trim="node.target_url" type="text" class="form-control" placeholder="https://… / /de/… / mailto:…" @blur="suggestNewWindow">
    </div>

    <div v-if="node.target_type !== 'none'" class="form-check">
      <input :id="'nw-' + node.key" v-model="node.new_window" type="checkbox" class="form-check-input">
      <label :for="'nw-' + node.key" class="form-check-label">{{ labels.newWindow }}</label>
    </div>
    <div class="form-check">
      <input :id="'on-' + node.key" type="checkbox" class="form-check-input" :checked="node.state === 2" @change="node.state = $event.target.checked ? 2 : 0">
      <label :for="'on-' + node.key" class="form-check-label">{{ labels.online }}</label>
    </div>
  </fieldset>
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
    labels: Object,
    disabled: Boolean
  },
  data() {
    return { activeLanguage: this.defaultLanguage };
  },
  computed: {
    targetOptions() {
      return [
        { value: 'content', label: this.labels.content },
        { value: 'url', label: this.labels.url },
        { value: 'none', label: this.labels.none }
      ];
    }
  },
  watch: {
    'node.key'() {
      this.activeLanguage = this.defaultLanguage;
    },
    'node.target_type'(type) {
      if (type === 'content' && !this.node.target_ctype && this.types.length) {
        this.node.target_ctype = this.types[0].ctype;
      }
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
.me-item-panel { border: 0; margin: 0; padding: 0; min-width: 0; }
</style>
