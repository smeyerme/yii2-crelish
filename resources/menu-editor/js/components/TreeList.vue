<template>
  <draggable
    :list="list"
    item-key="key"
    tag="ul"
    class="me-tree"
    :component-data="{ 'data-depth': depth }"
    :group="{ name: 'menu' }"
    handle=".me-handle"
    :move="onMove"
    :animation="150"
    :disabled="disabled"
    @start="$emit('drag-started')"
  >
    <template #item="{ element }">
      <li class="me-node">
        <div
          class="me-row"
          :class="{ 'me-row--selected': element.key === selectedKey, 'me-row--invalid': errors[element.key] }"
          @click="$emit('select', element.key)"
        >
          <span class="me-handle" title="⇅"><i class="fa-sharp fa-solid fa-grip-vertical"></i></span>
          <span class="me-label" :class="{ 'me-label--fallback': !element.label }">{{ displayLabel(element) }}</span>
          <span class="me-target">{{ targetSummary(element) }}</span>
          <span v-if="element.state !== 2" class="badge text-bg-secondary">{{ labels.offline }}</span>
          <span v-if="element.target_type === 'content' && element.targetAvailable === false" class="badge text-bg-danger">{{ labels.targetUnavailable }}</span>
          <button type="button" class="c-button me-remove" :title="labels.delete" :disabled="disabled" @click.stop="$emit('remove', element.key)">
            <i class="fa-sharp fa-regular fa-trash"></i>
          </button>
        </div>
        <TreeList
          v-if="depth < maxDepth"
          :list="element.children"
          :depth="depth + 1"
          :max-depth="maxDepth"
          :selected-key="selectedKey"
          :errors="errors"
          :labels="labels"
          :disabled="disabled"
          @select="$emit('select', $event)"
          @remove="$emit('remove', $event)"
          @drag-started="$emit('drag-started')"
          @refused="$emit('refused')"
        />
      </li>
    </template>
  </draggable>
</template>

<script>
import draggable from 'vuedraggable';
import { height } from '../tree';

export default {
  name: 'TreeList',
  components: { draggable },
  props: {
    list: Array,
    depth: Number,
    maxDepth: Number,
    selectedKey: String,
    errors: Object,
    labels: Object,
    disabled: Boolean
  },
  emits: ['select', 'remove', 'refused', 'drag-started'],
  methods: {
    onMove(evt) {
      const targetDepth = Number(evt.to.dataset.depth || 1);
      const ok = targetDepth + height(evt.draggedContext.element) - 1 <= this.maxDepth;
      if (!ok) this.$emit('refused');
      return ok;
    },
    displayLabel(node) {
      if (node.label) return node.label;
      if (node.fallbackLabel) return `${node.fallbackLabel} (${this.labels.fromTarget})`;
      return this.labels.untitled;
    },
    targetSummary(node) {
      if (node.target_type === 'url') return node.target_url;
      if (node.target_type === 'none') return this.labels.noLink;
      return node.targetTitle ? `${node.target_ctype}: ${node.targetTitle}` : node.target_ctype;
    }
  }
};
</script>

<style>
.me-tree { list-style: none; margin: 0; padding: 0 0 0 1.5rem; min-height: .75rem; }
.me-tree-wrap > .me-tree { padding-left: 0; }
.me-node { margin: .25rem 0; }
.me-row {
  display: flex;
  gap: .5rem;
  align-items: center;
  padding: .4rem .5rem;
  border: 1px solid var(--color-border);
  border-radius: var(--border-radius-md);
  background-color: var(--color-bg-main);
  color: var(--color-text-dark);
  cursor: pointer;
  transition: var(--transition-standard);
}
.me-row:hover { background-color: var(--color-bg-light); }
.me-row--selected,
.me-row--selected:hover {
  border-color: var(--color-primary-light);
  background-color: rgba(var(--color-primary-light-rgb), .15);
}
.me-row--invalid,
.me-row--invalid:hover { border-color: #dc3545; }
.me-handle { cursor: grab; color: var(--color-text-muted); }
.me-label { font-weight: 600; }
.me-label--fallback { font-style: italic; font-weight: 400; color: var(--color-text-muted); }
.me-target { color: var(--color-text-muted); font-size: .875rem; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.me .me-row button.c-button { margin-left: auto; padding: .25rem .5rem; }
</style>
