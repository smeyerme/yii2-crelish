<template>
  <div class="me-picker mb-3">
    <select v-model="node.target_ctype" class="form-select mb-2" @change="clearTarget">
      <option v-for="type in types" :key="type.ctype" :value="type.ctype">{{ type.label }}</option>
    </select>

    <div v-if="node.target_uuid" class="me-picked">
      <strong>{{ node.targetTitle || node.target_uuid }}</strong>
      <span v-if="node.targetAvailable === false" class="badge text-bg-danger">{{ labels.targetUnavailable }}</span>
    </div>

    <input v-model="query" type="search" class="form-control" :placeholder="labels.search" @input="scheduleSearch">
    <ul v-if="results.length" class="me-results">
      <li v-for="result in results" :key="result.uuid">
        <button type="button" class="me-result" @click="pick(result)">{{ result.title }}</button>
      </li>
    </ul>
    <p v-else-if="searched" class="me-empty">{{ labels.noResults }}</p>
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
    return { query: '', results: [], searched: false, timer: null };
  },
  methods: {
    clearTarget() {
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
      if (q.length < 2) {
        this.results = [];
        this.searched = false;
        return;
      }
      const url = `${this.searchUrl}${this.searchUrl.includes('?') ? '&' : '?'}ctype=${encodeURIComponent(this.node.target_ctype)}&q=${encodeURIComponent(q)}`;
      try {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        this.results = response.ok ? await response.json() : [];
      } catch (e) {
        this.results = [];
      }
      this.searched = true;
    },
    pick(result) {
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
.me-picked { display: flex; gap: .5rem; align-items: center; margin-bottom: .5rem; }
.me-results { list-style: none; padding: 0; margin: .25rem 0 0; border: 1px solid #ddd; border-radius: .25rem; max-height: 16rem; overflow: auto; }
.me-result { display: block; width: 100%; text-align: left; border: 0; background: none; padding: .35rem .5rem; }
.me-result:hover { background: #f3eee9; }
</style>
