<template>
  <div class="section" :class="[containerClass]" :id="container">
    <h3 class="title">{{ title }}</h3>
    <div class="button-container" :id="id" :path="path">
      <editableRow
        v-for="(option, key) in rows"
        v-bind:key="key"
        :value="option.value"
        :name="option.name"
        :placeholder="option.placeholder"
      />
      <button
        type="button"
        class="custom-settings-add-btn"
        @click.prevent="newOption($event, name)"
        data-tippy-content="Add new options"
      >
        <slot name="add">New Option</slot>
      </button>
      <button
        type="button"
        class="custom-settings-save-btn"
        @click.prevent="saveOptions"
        :data-rel="id"
      >
        <slot name="save">Save</slot>
      </button>
    </div>
  </div>
</template>
<script>
import helper from "../utils/helper";
import settingsForm from "../lib/settingsForm";
import editableRow from "./editableRow";

export default {
  name: "customOptions",
  props: {
    path: String,
    name: {
      type: String,
      default: "settings",
    },
    title: {
      type: String,
      default: "Custom Settings",
    },
    classes: String,
    validOptions: Array,
  },
  data() {
    return {
      id: "custom-" + this.name,
      containerClass: this.classes || "custom-settings-container",
      container: "custom-settings-container",
      options: [],
    };
  },
  components: {
    editableRow,
  },
  computed: {
    rows() {
      return this.options;
    },
  },
  methods: {
    newOption(e, baseName) {
      e.stopPropagation();
      const element = e.target;
      const nodeList = document.querySelectorAll(`[id^='${baseName}-key']`);
      const index = nodeList.length + 1;
      const key = `${baseName}-key-${index}`;
      const value = `${baseName}-value-${index}`;
      const form = settingsForm.getInstance();
      element.before(form.createInputGroup(key, value));
      helper.autoComplete(`[id^='${baseName}-key']`, this.validOptions || []);
    },
    saveOptions(e) {
      const element = e.target;
      const container = element.getAttribute("data-rel");
      let data = helper.getData(container);
      const url = helper.generateUrl(data._path);
      data = helper.transformParams(data, this.name);
      const allowed = Array.isArray(this.validOptions) ? this.validOptions : [];
      const badOptions = [];
      for (const name in data) {
        if (!allowed.includes(name)) {
          badOptions.push(name);
        }
      }
      if (badOptions.length > 0) {
        helper.error("invalid options: " + badOptions.join(","));
        return;
      }
      helper
        .httpClient(url)
        .setData(data)
        .setHandler((resp) => {
          if (resp && resp.error) {
            helper.error(resp.error);
            return;
          }
          this.options = [];
          for (const key in data) {
            this.options.push({ name: key, value: data[key] });
          }
          const inputDiv = element.parentElement.querySelectorAll(
            `div[id^='${this.name}-key']`
          );
          if (inputDiv && inputDiv.length > 0) {
            inputDiv.forEach((node) => {
              node.remove();
            });
          }
          helper.info((resp && resp.message) || "Saved!");
        })
        .send();
    },
  },
  mounted() {
    this.$emit("mounted", null, this);
  },
};
</script>
<style scoped lang="scss"></style>
