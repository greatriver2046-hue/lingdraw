import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

const mergeRules = (...ruleSets) => Object.assign({}, ...ruleSets.filter(Boolean))

const toWarnRules = (rules = {}) =>
  Object.fromEntries(
    Object.entries(rules).map(([key, val]) => {
      if (val === 'off' || val === 0) return [key, 'off']
      if (Array.isArray(val)) return [key, ['warn', ...val.slice(1)]]
      return [key, 'warn']
    })
  )

const baseRules = mergeRules(
  js.configs.recommended.rules,
  ...pluginVue.configs['flat/essential'].map((c) => c.rules)
)

export default [
  { languageOptions: { globals: globals.browser } },
  js.configs.recommended,
  ...pluginVue.configs['flat/essential'],
  {
    rules: {
      ...toWarnRules(baseRules),
      'vue/multi-word-component-names': 'off',
      'no-unused-vars': 'warn'
    }
  }
]
