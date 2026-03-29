module.exports = {
  content: [
    'packages/*/src/**/*.php',
    'packages/*/assets/**/*.js',
    'packages/*/templates/**/*.twig'
  ],
  css: ['packages/*/assets/**/*.css'],
  safelist: {
    standard: [
      /^sv2-/,
      /^synapse-/,
      /^is-/,
      /^has-/,
      /^js-/
    ],
    deep: [/^flatpickr/, /^hljs/],
    greedy: [/^sv2-/]
  },
  extractors: [
    {
      extractor: (content) => content.match(/[A-Za-z0-9-_:\/]+/g) || [],
      extensions: ['twig', 'php', 'js']
    }
  ],
  dynamicAttributes: ['data-theme', 'data-state']
};
