<template>
  <div class="me-picker mb-3">
    <label class="form-label" :for="'ctype-' + node.key">{{ labels.contentType }}</label>
    <select :id="'ctype-' + node.key" v-model="node.target_ctype" class="form-select mb-2" @change="clearTarget">
      <option v-for="type in types" :key="type.ctype" :value="type.ctype">{{ type.label }}</option>
    </select>

    <div v-if="node.target_uuid" class="d-flex flex-wrap align-items-center gap-2 mb-2">
      <span class="text-muted">{{ labels.target }}:</span>
      <strong>{{ node.targetTitle || node.target_uuid }}</strong>
      <span v-if="node.targetAvailable === false" class="badge text-bg-danger">{{ labels.targetUnavailable }}</span>
    </div>

    <input v-model="query" type="search" class="form-control" :placeholder="labels.search" autocomplete="off" @input="scheduleSearch">
    <div v-if="results.length" class="list-group me-results mt-1">
      <button v-for="result in results" :key="result.uuid" type="button" class="list-group-item list-group-item-action" @click="pick(result)">
        {{ result.title }}
      </button>
    </div>
    <p v-else-if="searched" class="text-muted small mt-1 mb-0">{{ labels.noResults }}</p>
  </div>
</template>

<script>
export default {
  name: 'TargetPicker',
  props: {
    node: Object,
    types: Array,
    searchUrl: String,
    labels: Object
  },
  data() {
    return { query: '', results: [], searched: false, timer: null, requestId: 0 };
  },
  watch: {
    'node.key'() {
      this.cancelPending();
      this.results = [];
      this.query = '';
      this.searched = false;
    }
  },
  beforeUnmount() {
    this.cancelPending();
  },
  methods: {
    cancelPending() {
      clearTimeout(this.timer);
      this.timer = null;
      this.requestId++;
    },
    clearTarget() {
      this.cancelPending();
      this.node.target_uuid = null;
      this.node.targetTitle = null;
      this.node.fallbackLabel = null;
      this.node.targetAvailable = true;
      this.results = [];
      this.searched = false;
    },
    scheduleSearch() {
      clearTimeout(this.timer);
      this.timer = setTimeout(this.search, 250);
    },
    async search() {
      const q = this.query.trim();
      const ctype = this.node.target_ctype;
      const requestId = ++this.requestId;
      if (q.length < 2) {
        this.results = [];
        this.searched = false;
        return;
      }
      const url = `${this.searchUrl}${this.searchUrl.includes('?') ? '&' : '?'}ctype=${encodeURIComponent(ctype)}&q=${encodeURIComponent(q)}`;
      let results;
      try {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        results = response.ok ? await response.json() : [];
      } catch (e) {
        results = [];
      }
      // Ignore stale responses (newer request, or ctype/query changed meanwhile)
      if (requestId !== this.requestId || ctype !== this.node.target_ctype || q !== this.query.trim()) return;
      this.results = results;
      this.searched = true;
    },
    pick(result) {
      this.cancelPending();
      this.node.target_uuid = result.uuid;
      this.node.targetTitle = result.title;
      this.node.fallbackLabel = result.title;
      this.node.targetAvailable = true;
      this.results = [];
      this.query = '';
      this.searched = false;
    }
  }
};
</script>

<style>
.me-results { max-height: 16rem; overflow: auto; }
.me .list-group-item {
  background-color: var(--color-bg-main);
  color: var(--color-text-dark);
  border-color: var(--color-border);
}
.me .list-group-item-action:hover,
.me .list-group-item-action:focus {
  background-color: var(--color-bg-light);
  color: var(--color-text-dark);
}
</style>
