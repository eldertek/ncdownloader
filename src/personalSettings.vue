<template>
  <div class="section ncdownloader-general-settings">
    <h3>General Settings</h3>
    <settingsRow v-for="(option, key) in optionRows" v-bind:key="key" :value="option.value" :id="option.id"
      :label="option.label" :placeholder="option.placeholder" :path="option.path" :useBtn="true" />
  </div>
  <customOptions v-if="!disallowAria2Settings || isAdmin" name="custom-aria2-settings" title="Personal Aria2 Settings"
    @mounted="renderAria2" path="/apps/ncdownloader/personal/aria2/save" :validOptions="aria2Options">
    <template #save>Save Aria2 Settings</template>
  </customOptions>
  <customOptions name="custom-ytdl-settings" title="Personal yt-dlp Settings" @mounted="renderYtdl"
    path="/apps/ncdownloader/personal/ytdl/save" :validOptions="ytdlOptions">
    <template #save>Save yt-dlp Settings</template>
  </customOptions>
</template>
<script>
import customOptions from "./components/customOptions";
import helper from "./utils/helper";
import aria2Options from "./utils/aria2Options";
import { names as ytdlOptions } from "./utils/ytdlOptions";
import settingsRow from "./components/settingsRow";

export default {
  name: "personalSettings",
  data() {
    return {
      options: [],
      aria2Options: aria2Options,
      ytdlOptions: ytdlOptions,
      disallowAria2Settings: false,
      isAdmin: false,
    };
  },
  components: {
    customOptions,
    settingsRow,
  },
  methods: {
    renderAria2(_event, $vm) {
      helper
        .httpClient(helper.generateUrl("/apps/ncdownloader/personal/aria2/get"))
        .setHandler((data) => {
          if (!data || typeof data !== "object") {
            return;
          }
          const input = [];
          for (const key in data) {
            if (aria2Options.includes(key)) {
              input.push({ name: key, value: data[key], id: key });
            }
          }
          $vm.options = input;
        })
        .send();
    },
    renderYtdl(_event, $vm) {
      helper
        .httpClient(helper.generateUrl("/apps/ncdownloader/personal/ytdl/get"))
        .setHandler((data) => {
          if (!data || typeof data !== "object") {
            return;
          }
          const input = [];
          for (const key in data) {
            if (ytdlOptions.includes(key)) {
              input.push({ name: key, value: data[key], id: key });
            }
          }
          $vm.options = input;
        })
        .send();
    },
  },
  computed: {
    optionRows() {
      return this.options;
    },
  },
  mounted() {
    try {
      const hostEl = document.getElementById("ncdownloader-personal-settings") || (this.$el && this.$el.parentElement);
      if (!hostEl) {
        return;
      }
      const rawSettings = hostEl.getAttribute("data-settings");
      const rawOptions = hostEl.getAttribute("data-options");
      const data = rawSettings ? JSON.parse(rawSettings) : {};
      const options = rawOptions ? JSON.parse(rawOptions) : [];
      this.disallowAria2Settings = helper.str2Boolean(String(data.disallow_aria2_settings || ""));
      this.isAdmin = Boolean(data.is_admin);
      this.options = Array.isArray(options) ? options : [];
    } catch (e) {
      helper.error(String(e));
    }
  },
};
</script>
