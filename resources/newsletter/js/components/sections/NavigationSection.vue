<!-- components/sections/NavigationSection.vue -->
<template>
  <div class="navigation-section-editor">
    <h2>Navigation Links</h2>

    <VueDraggable
        v-model="section.content.links"
        item-key="text"
        handle=".link-handle"
        @end="updateSection"
        class="links-list"
    >
      <template  v-for="(element, index) in section.content.links">
        <div class="link-item">
          <div class="link-handle">☰</div>
          <div class="link-content">
            <div class="form-row">
              <div class="form-group">
                <label>Link Text</label>
                <input
                    type="text"
                    v-model="element.text"
                    class="form-control"
                    placeholder="Link Text"
                    @input="updateSection"
                />
              </div>
              <div class="form-group">
                <label>URL</label>
                <input
                    type="text"
                    v-model="element.url"
                    class="form-control"
                    placeholder="https://example.com"
                    @input="updateSection"
                />
              </div>
            </div>
            <div class="form-group">
              <label>Link Image (Optional)</label>
              <ImageSelector
                  :selected-id="element.imageId"
                  @select="onImageSelected(index, $event)"
              />
            </div>
          </div>
          <button @click.stop="removeLink(index)" class="btn btn-sm btn-danger">×</button>
        </div>
      </template>
    </VueDraggable>

    <button @click="addLink" class="btn btn-primary add-link-btn">
      <span>+ Add Link</span>
    </button>
  </div>
</template>

<script>
import { VueDraggable } from 'vue-draggable-plus';
import ImageSelector from '../common/ImageSelector.vue';

export default {
  components: {
    VueDraggable,
    ImageSelector
  },

  props: {
    section: {
      type: Object,
      required: true
    }
  },

  methods: {
    addLink() {
      this.section.content.links.push({
        text: 'New Link',
        url: 'https://',
        imageId: null
      });
      this.updateSection();
    },

    removeLink(index) {
      this.section.content.links.splice(index, 1);
      this.updateSection();
    },

    onImageSelected(index, imageId) {
      this.section.content.links[index].imageId = imageId;
      this.updateSection();
    },

    updateSection() {
      this.$emit('update', JSON.parse(JSON.stringify(this.section)));
    }
  }
}
</script>

<style>
.link-item {
  display: flex;
  margin-bottom: 1.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  background-color: #fff;
}

.link-handle {
  padding: 1rem;
  cursor: move;
  color: #999;
  border-right: 1px solid #ddd;
  display: flex;
  align-items: center;
}

.link-content {
  flex: 1;
  padding: 1rem;
}
</style>