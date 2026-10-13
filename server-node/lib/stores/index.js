'use strict';
const { JsonStore } = require('./json-store');
const { MysqlStore } = require('./mysql-store');

async function createStore(storage) {
  let store;
  switch (storage.driver) {
    case 'json': store = new JsonStore(storage.json); break;
    case 'mysql': store = new MysqlStore(storage.mysql); break;
    default: throw new Error('Unknown storage driver: ' + storage.driver);
  }
  await store.init();
  return store;
}

module.exports = { createStore };
