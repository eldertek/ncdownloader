<template>
  <div class="text-input-link" id="text-input-link">
    <input
      type="text"
      name="text-input-value"
      id="text-input-value"
      v-bind:placeholder="placeholder"
      v-bind:data-type="dataType"
    />
  </div>
</template>
<script>
export default {
  name: "textinput",
  props: {
    placeholder: String,
    dataType: String,
  },
};
</script>
<style scoped lang="scss">
@import "../css/variables.scss";
#text-input-link {
  position: relative;
  width: 100%;
  flex: 2;
  input {
    width: 100%;
    height: 100%;
    min-height: 40px;
    margin: 0;
    background-color: var(--color-main-background, $bg-color);
    font-size: medium;
    color: var(--color-main-text, #181616);
    border-radius: 0;
  }
}
@media only screen and (max-width: 1024px) {
  #text-input-link {
    width: 100%;
    & > input {
      width: 100%;
    }
    & > input:focus {
      border-color: var(--color-primary-element, #86b7fe);
      outline: 0;
    }
  }
}
</style>
