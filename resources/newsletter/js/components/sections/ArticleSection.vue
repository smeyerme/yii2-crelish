<!-- components/sections/ArticleSection.vue -->
<template>
  <div class="article-section-editor">
    <h2>Articles Section</h2>

    <div class="form-group">
      <label>Section Title</label>
      <input
          type="text"
          v-model="localSection.content.title"
          class="form-control"
          placeholder="Section Title (e.g., PRESSEMELDUNGEN)"
          @input="updateSection"
      />
    </div>

    <div class="form-group">
      <label>Zeige Trennlinie</label>
      <select v-model="localSection.content.showDivider" class="form-control" @change="updateSection">
        <option :value="true">Ja</option>
        <option :value="false">Nein</option>
      </select>
    </div>

    <div class="form-group">
      <label>Layout</label>
      <select v-model="localSection.content.layout" class="form-control" @change="updateSection">
        <option value="single">Eine Spalte</option>
        <option value="double">Zwei Spalten</option>
      </select>
    </div>

    <div class="articles-container">
      <h3>Articles</h3>

      <VueDraggable
          id="articles"
          v-model="localSection.content.articles"
          item-key="id"
          handle=".article-handle"
          @end="updateSection"
          group="b"
          class="articles-list"
      >
        <template v-for="(element, index) in localSection.content.articles">
          <div class="article-item">
            <div class="article-handle">☰</div>
            <div class="article-content">
              <div class="article-header">
                <h4>Article {{ index + 1 }}</h4>
              </div>

              <div class="form-group">
                <label>Title</label>
                <input
                    type="text"
                    v-model="element.title"
                    class="form-control"
                    placeholder="Article Title"
                    @input="updateSection"
                />
              </div>

              <div class="form-group">
                <label>Image</label>
                <ImageSelector
                    :selected-id="element.imageId"
                    @select="(imageId) => onImageSelected(index, imageId)"
                />
              </div>

              <div class="form-group">
                <label>Text</label>
                <textarea
                    v-model="element.text"
                    class="form-control"
                    rows="4"
                    placeholder="Article text content"
                    @input="updateSection"
                ></textarea>
              </div>

              <div class="form-group">
                <label>Link URL</label>
                <input
                    type="text"
                    v-model="element.link"
                    class="form-control"
                    placeholder="https://example.com/article"
                    @input="updateSection"
                />
              </div>
            </div>
            <button @click.stop="removeArticle(index)" class="btn btn-sm btn-danger">x</button>
          </div>
        </template>
      </VueDraggable>

      <button @click="addArticle" class="btn btn-primary add-article-btn">
        <span>+ Add Article</span>
      </button>
    </div>
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

  data() {
    return {
      localSection: null
    };
  },

  created() {
    // Initialize articles array if it doesn't exist
    this.initLocalSection();
  },

  watch: {
    // Watch for external changes to section
    section: {
      handler() {
        this.initLocalSection();
      },
      deep: true
    }
  },

  methods: {
    initLocalSection() {
      // Create a deep clone of the section to avoid direct modifications
      this.localSection = JSON.parse(JSON.stringify(this.section));

      // Initialize articles array if needed
      if (!this.localSection.content) {
        this.localSection.content = {};
      }

      if (!this.localSection.content.articles) {
        this.localSection.content.articles = [];
      }

      // Initialize showDivider if not set
      if (this.localSection.content.showDivider === undefined) {
        this.localSection.content.showDivider = false;
      }

      // Initialize layout if not set
      if (!this.localSection.content.layout) {
        this.localSection.content.layout = 'single';
      }
    },

    addArticle() {
      this.localSection.content.articles.push({
        id: Date.now(),
        title: '',
        imageId: null,
        text: '',
        link: ''
      });

      this.updateSection();
    },

    removeArticle(index) {
      if (confirm('Are you sure you want to remove this article?')) {
        this.localSection.content.articles.splice(index, 1);
        this.updateSection();
      }
    },

    onImageSelected(articleIndex, imageId) {
      this.localSection.content.articles[articleIndex].imageId = imageId;
      this.updateSection();
    },

    updateSection() {
      // Emit update event with a deep clone of the local section
      this.$emit('update', JSON.parse(JSON.stringify(this.localSection)));
    }
  }
};
</script>

<style>
.article-section-editor {
  padding: 1rem;
}

.articles-container {
  margin-top: 1.5rem;
}

.articles-list {
  min-height: 50px;
}

.article-item {
  display: flex;
  margin-bottom: 1.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  background-color: #fff;
}

.article-handle {
  padding: 1rem;
  cursor: move;
  color: #999;
  border-right: 1px solid #ddd;
  display: flex;
  align-items: center;
}

.article-content {
  flex: 1;
  padding: 1rem;
}

.article-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1rem;
}

.article-header h4 {
  margin: 0;
}

.add-article-btn {
  margin-top: 1rem;
  width: 100%;
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
}

.form-control {
  width: 100%;
  padding: 0.375rem 0.75rem;
  font-size: 1rem;
  line-height: 1.5;
  color: #495057;
  background-color: #fff;
  background-clip: padding-box;
  border: 1px solid #ced4da;
  border-radius: 0.25rem;
  transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
}

.btn {
  display: inline-block;
  font-weight: 400;
  text-align: center;
  white-space: nowrap;
  vertical-align: middle;
  user-select: none;
  border: 1px solid transparent;
  padding: 0.375rem 0.75rem;
  font-size: 1rem;
  line-height: 1.5;
  border-radius: 0.25rem;
  transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
  cursor: pointer;
}

.btn-primary {
  color: #fff;
  background-color: #007bff;
  border-color: #007bff;
}

.btn-danger {
  color: #fff;
  background-color: #dc3545;
  border-color: #dc3545;
}

.btn-sm {
  padding: 0.25rem 0.5rem;
  font-size: 0.875rem;
  line-height: 1.5;
  border-radius: 0.2rem;
}
</style>