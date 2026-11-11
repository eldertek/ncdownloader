<template>
  <div class="section">
    <settingsRow v-for="(option, key) in optionRows" v-bind:key="key" :value="option.value" :id="option.id"
      :label="option.label" :placeholder="option.placeholder" :path="option.path" />
  </div>
  <div class="section">
    <toggleButton :defaultStatus="pStatus" disabledText="No Aria2 Settings for non-admin Users"
      enabledText="No Aria2 Settings for non-admin Users" @changed="toggle" name="disallow_aria2_settings">
    </toggleButton>
  </div>
  <customOptions name="admin-aria2-settings" @mounted="render" title="Global Aria2 Settings"
    path="/apps/ncdownloader/admin/aria2/save" :validOptions="validOptions">
    <template #save>Save Settings</template>
  </customOptions>
  <systemInfo :aria2Version="aria2Version" :ytdVersion="ytdVersion" />
</template>
<script>
import customOptions from "./components/customOptions";
import helper from "./utils/helper";
import aria2Options from "./utils/aria2Options";
import settingsRow from "./components/settingsRow";
import toggleButton from "./components/toggleButton";
import systemInfo from "./components/systemInfo";

export default {
  name: "adminSettings",
  data() {
    return {
      options: [],
      validOptions: aria2Options,
      settings: {},
      pStatus: false,
      aria2Version: "",
      ytdVersion: "",
    };
  },
  components: {
    customOptions,
    settingsRow,
    toggleButton,
    systemInfo,
  },
  methods: {
    toggle(name, value) {
      const data = {};
      data[name] = value ? 1 : 0;
      const url = helper.generateUrl("/apps/ncdownloader/admin/save");
      helper
        .httpClient(url)
        .setData(data)
        .setHandler((resp) => {
          if (resp && resp.message) {
            helper.message(helper.t(resp.message), 1000);
          }
        })
        .send();
    },
    render(_event, $vm) {
      helper
        .httpClient(helper.generateUrl("/apps/ncdownloader/admin/aria2/get"))
        .setMethod("GET")
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
  },
  computed: {
    optionRows() {
      return this.options;
    },
  },
  mounted() {
    try {
      const hostEl = document.getElementById("ncdownloader-admin-settings") || (this.$el && this.$el.parentElement);
      if (!hostEl) {
        return;
      }
      const rawSettings = hostEl.getAttribute("data-settings");
      const rawOptions = hostEl.getAttribute("data-options");
      const data = rawSettings ? JSON.parse(rawSettings) : {};
      const options = rawOptions ? JSON.parse(rawOptions) : [];
      this.settings = data;
      this.pStatus = helper.str2Boolean(String(data.disallow_aria2_settings || ""));
      this.aria2Version = data.aria2_version || "";
      this.ytdVersion = data.ytdl_version || "";
      this.options = Array.isArray(options) ? options : [];
    } catch (e) {
      helper.error(String(e));
    }
  },
};
</script>
