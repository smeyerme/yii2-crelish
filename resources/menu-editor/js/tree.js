let counter = 0;

export function newClientId() {
  counter += 1;
  return `new-${Date.now()}-${counter}`;
}

/** Flat server rows → nested nodes with a stable `key` (uuid or clientId). */
export function nest(rows) {
  const byId = new Map();
  rows.forEach((row) => byId.set(row.uuid, { ...row, key: row.uuid, clientId: null, children: [] }));

  const roots = [];
  rows.forEach((row) => {
    const node = byId.get(row.uuid);
    const parent = row.parentRef ? byId.get(row.parentRef) : null;
    (parent ? parent.children : roots).push(node);
  });

  const sortRec = (list) => {
    list.sort((a, b) => a.sort - b.sort);
    list.forEach((n) => sortRec(n.children));
  };
  sortRec(roots);

  return roots;
}

/** Nested nodes → payload items with parentRef and sort from the current order. */
export function flatten(list, parentRef = null, out = []) {
  list.forEach((node, index) => {
    out.push({
      uuid: node.uuid,
      clientId: node.uuid ? null : node.clientId,
      parentRef,
      sort: index,
      label: node.label || '',
      i18n: { ...node.i18n },
      target_type: node.target_type,
      target_ctype: node.target_ctype,
      target_uuid: node.target_uuid,
      target_url: node.target_url,
      new_window: !!node.new_window,
      state: node.state
    });
    flatten(node.children, node.uuid || node.clientId, out);
  });
  return out;
}

/** Levels of a subtree including the node itself. */
export function height(node) {
  return 1 + node.children.reduce((max, child) => Math.max(max, height(child)), 0);
}

export function findNode(list, key) {
  for (const node of list) {
    if (node.key === key) return node;
    const found = findNode(node.children, key);
    if (found) return found;
  }
  return null;
}

export function removeNode(list, key) {
  const index = list.findIndex((n) => n.key === key);
  if (index !== -1) {
    list.splice(index, 1);
    return true;
  }
  return list.some((n) => removeNode(n.children, key));
}

export function isExternalUrl(url) {
  try {
    return /^https?:\/\//i.test(url) && new URL(url).host !== window.location.host;
  } catch (e) {
    return false;
  }
}
